<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Access\AccessManager;
use App\Entity\Domain;
use App\Entity\ResourceType;
use App\Entity\User;
use GuillaumeTissier\ResourceGuard\Access\AccessExpression;

/**
 * Accounts and domains for the administration tests: a1 > a10 > a100, and a1 > a11.
 *  - root:    super-administrator, no domain;
 *  - support: platform administrator (ROLE_ADMIN, read-only), no domain;
 *  - owner:   member and owner of a10 (owner rights "*:m @access:w @domain:w");
 *  - manager: member of a10, "@access:w @domain:w vehicle:w" on a10 and subdomains;
 *  - reader:  member of a10, "vehicle:r" on a10;
 *  - dealer:  member of a100;
 *  - south:   member of a11 (sibling).
 * Nobody has two-factor authentication, so that a test can sign in as several users.
 */
abstract class AdminTestCase extends OAuthTestCase
{
    /** @var array<string, Domain> */
    protected array $tree = [];

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        $em->persist(new ResourceType('vehicle', 'Vehicles'));
        $em->flush();

        $manager = static::getContainer()->get(AccessManager::class);
        $this->tree['a1'] = $manager->createDomain('a1', 'Group', null);
        $this->tree['a10'] = $manager->createDomain('a10', 'North', $this->tree['a1']);
        $this->tree['a100'] = $manager->createDomain('a100', 'North depot', $this->tree['a10']);
        $this->tree['a11'] = $manager->createDomain('a11', 'South', $this->tree['a1']);

        foreach (['root' => null, 'support' => null, 'owner' => 'a10', 'manager' => 'a10', 'reader' => 'a10', 'dealer' => 'a100', 'south' => 'a11'] as $name => $domain) {
            [$user] = $this->createUser($name, 'app-'.$name, withTotp: false);
            $user->setEmail($name.'@example.test');
            if (null !== $domain) {
                $manager->moveUser($user, $this->tree[$domain], null);
            }
        }
        $this->userNamed('root')->addRole(User::ROLE_SUPER_ADMIN);
        $this->userNamed('support')->addRole(User::ROLE_ADMIN);
        $em->flush();
        $manager->setOwner($this->tree['a10'], $this->userNamed('owner'), AccessExpression::parse(Domain::DEFAULT_OWNER_EXPRESSION), null);
        $manager->grant($this->userNamed('manager'), $this->tree['a10'], AccessExpression::parse('@access:w @domain:w vehicle:w'), true, null);
        $manager->grant($this->userNamed('reader'), $this->tree['a10'], AccessExpression::parse('vehicle:r'), false, null);
        $this->em()->clear();
    }

    protected function signIn(string $username): void
    {
        $this->browser->getCookieJar()->clear();
        $this->submitLogin($username);
        self::assertResponseRedirects('http://localhost/dashboard', null, 'sign-in of '.$username);
    }

    /**
     * Submits a form of the current page, found by its action.
     *
     * @param array<string, string> $values
     */
    protected function submitForm(string $action, array $values = []): void
    {
        $form = $this->browser->getCrawler()->filter(\sprintf('form[action="%s"]', $action))->form();
        $this->browser->submit($form, $values);
    }

    protected function reload(User $user): User
    {
        $this->em()->clear();
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
