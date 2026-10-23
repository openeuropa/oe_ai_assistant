<?php

declare(strict_types=1);

namespace Drupal\Tests\oe_ai_assistant\Traits;

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Trait that sets up a current user with given text format permissions.
 */
trait TextFormatUserTrait {

  /**
   * Makes the current user one who may use exactly the given formats.
   *
   * @param string[] $formats
   *   The text format IDs the user may use.
   * @param string[] $permissions
   *   Further permissions the user has.
   */
  protected function setUserWithFormats(array $formats, array $permissions = []): void {
    $role = Role::create(['id' => 'oe_test_formats', 'label' => 'Test formats']);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    foreach ($formats as $format) {
      $role->grantPermission("use text format $format");
    }
    $role->save();
    // Uid 1 bypasses all permission checks; consume it so the real test user
    // below is subject to the ordinary permission check the test exercises.
    User::create(['name' => 'Uid 1 placeholder'])->save();
    $user = User::create(['name' => 'Format tester', 'roles' => ['oe_test_formats']]);
    $user->save();
    $this->container->get('current_user')->setAccount($user);
  }

}
