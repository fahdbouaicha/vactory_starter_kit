<?php

namespace Drupal\vactory_decoupled\Controller;

use Drupal\Core\Flood\FloodInterface;
use Drupal\simple_oauth\Controller\Oauth2Token;
use Drupal\simple_oauth\Plugin\Oauth2GrantManagerInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Class DecoupledOauth2Token
 *
 * @package Drupal\vactory_decoupled\Controller
 */
class DecoupledOauth2Token extends Oauth2Token {

  /**
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * User limit.
   */
  protected $userLimit;

  /**
   * User window.
   */
  protected $userWindow;


  /**
   * DecoupledOauth2Token constructor.
   */
  public function __construct(Oauth2GrantManagerInterface $grant_manager, ClientRepositoryInterface $client_repository, FloodInterface $flood, ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entityTypeManager,
                              ModuleHandlerInterface $moduleHandler) {
    parent::__construct($grant_manager, $client_repository);
    $this->flood = $flood;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;

    if ($this->moduleHandler->moduleExists('flood_control')) {
      $this->userLimit = $this->configFactory->get('user.flood')
          ->get('user_limit') ?? 5;
      $this->userWindow = $this->configFactory->get('user.flood')
          ->get('user_window') ?? 300;
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.oauth2_grant.processor'),
      $container->get('simple_oauth.repositories.client'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * Processes POST requests to /oauth/token.
   */
  public function token(ServerRequestInterface $request) {
    $response = parent::token($request);

    $body = $request->getParsedBody();
    if (!empty($body['username']) && !empty($body['password'])) {

      $account_search = $this->entityTypeManager
        ->getStorage('user')
        ->loadByProperties(['name' => $body['username']]);

      if ($account = reset($account_search)) {

        $isAllowed = $this->isAllowed($account->id());

        if (!$isAllowed) {
          return new JsonResponse([
            'error' => 'flood_control_error',
            'message' => sprintf('There have been more than %s failed login attempts for this account. It is temporarily blocked. Try again later or request a new password.', $this->userLimit),
          ], 400);
        }

        if ($response->getStatusCode() !== 200) {
          $this->logAuthFailure($body);
          $this->flood->register('user.failed_login_user', $this->userWindow, $account->id());
        }

        if ($response->getStatusCode() === 200) {
          $this->logAuthSuccess($body);
        }

      }
      else {
        $this->logAuthFailure($body);
      }

    }

    return $response;
  }

  /**
   * Log authentication failures.
   */
  protected function logAuthFailure($body) {
    if (\Drupal::moduleHandler()->moduleExists('vactory_security_review')) {
      $is_failed_login_log_enabled = \Drupal::config('security_review.checks')->get('log_failed_auth');
      $ip = \Drupal::request()->getClientIp();
      if ($is_failed_login_log_enabled) {
        \Drupal::logger('user')->info("Login attempt failed from: <br>IP: {$ip}<br>Username: {$body['username']}");
      }
    }
  }

  /**
   * Log authentication success.
   */
  protected function logAuthSuccess($body) {
    if (\Drupal::moduleHandler()->moduleExists('vactory_security_review')) {
      $is_failed_login_log_enabled = \Drupal::config('security_review.checks')->get('failed_auth_log');
      if ($is_failed_login_log_enabled) {
        \Drupal::logger('user')->info("Session opened for {$body['username']} via Oauth2");
      }
    }
  }


  /**
   * Check if the user is allowed or not to login.
   *
   * @return bool
   */
  protected function isAllowed($uid) {
    return $this->flood->isAllowed('user.failed_login_user', $this->userLimit, $this->userWindow, $uid);
  }

}
