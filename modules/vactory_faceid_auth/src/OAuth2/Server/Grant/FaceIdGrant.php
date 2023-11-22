<?php

namespace Drupal\vactory_faceid_auth\OAuth2\Server\Grant;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\simple_oauth\Entities\UserEntity;
use Drupal\user\UserAuthInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Password grant class.
 */
class FaceIdGrant extends PasswordGrant {

  /**
   * User auth service.
   *
   * @var \Drupal\user\UserAuthInterface
   */
  protected $userAuth;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    UserRepositoryInterface $userRepository,
    RefreshTokenRepositoryInterface $refreshTokenRepository,
    UserAuthInterface $userAuth,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct($userRepository, $refreshTokenRepository);
    $this->userAuth = $userAuth;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client) {
    $user_uuid = $this->getRequestParameter('user_uuid', $request);
    if (!\is_string($user_uuid)) {
      throw OAuthServerException::invalidRequest('user_uuid');
    }

    $face_id = $this->getRequestParameter('face_id', $request);
    if (!\is_string($face_id)) {
      throw OAuthServerException::invalidRequest('face_id');
    }

    $user = $this->getUserEntityByUserFaceId($user_uuid, $face_id);

    if ($user instanceof UserEntityInterface === FALSE) {
      $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

      throw OAuthServerException::invalidCredentials();
    }

    return $user;
  }

  /**
   * Get user entity by user face id.
   */
  protected function getUserEntityByUserFaceId($user_uuid, $user_face_id) {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties([
        'uuid' => $user_uuid,
        'face_id' => $user_face_id,
      ]);

    if (!empty($users)) {
      $user = reset($users);
      user_login_finalize($user);
      $user_entity = new UserEntity();
      $user_entity->setIdentifier($user->id());
      return $user_entity;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdentifier() {
    return 'faceid';
  }

}
