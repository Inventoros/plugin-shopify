<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may work in an organization.
 *
 * users.organization_id is only the HOME organization. On cores with
 * organization memberships (Inventoros#257) a user can also work in other
 * organizations (Multi-Company, 3PL operator staff in client organizations),
 * so matching users on users.organization_id alone leaves those members out.
 * Everything here goes through core's ActiveOrganization and
 * OrganizationMembershipService when they exist, and falls back to the home
 * organization on older cores, which have no other kind of member.
 */
final class Members
{
    private const ACTIVE = 'App\\Services\\Organizations\\ActiveOrganization';

    private const MEMBERSHIPS = 'App\\Services\\Organizations\\OrganizationMembershipService';

    /**
     * $user (or the user with that id) working in $organizationId, with their
     * role and permissions there, or null when they are not a member. Never
     * use a stored user row as-is for an organization: its organization_id
     * is the home organization.
     */
    public static function userIn(User|int|null $user, int $organizationId): ?User
    {
        if ($user === null || $user === 0) {
            return null;
        }

        if (class_exists(self::ACTIVE)) {
            return app(self::ACTIVE)->userIn($user, $organizationId);
        }

        $user = $user instanceof User ? $user : User::query()->find($user);

        return $user !== null && (int) $user->organization_id === $organizationId ? $user : null;
    }

    /**
     * Whether the user may work in $organizationId.
     */
    public static function isMember(User|int|null $user, int $organizationId): bool
    {
        return self::userIn($user, $organizationId) !== null;
    }

    /**
     * Everyone who can work in $organizationId (home users and members who
     * joined from another organization), as a query to constrain further.
     * The rows carry their HOME organization: map them through userIn() (or
     * use all()) before checking roles or permissions.
     *
     * @return Builder<User>
     */
    public static function query(int $organizationId): Builder
    {
        if (class_exists(self::MEMBERSHIPS)) {
            return app(self::MEMBERSHIPS)->members($organizationId);
        }

        return User::query()->where('users.organization_id', $organizationId);
    }

    /**
     * Every member of $organizationId, each loaded working there (role and
     * permissions of that organization), ordered by id.
     *
     * @param  (callable(Builder<User>): mixed)|null  $constrain
     * @return Collection<int, User>
     */
    public static function all(int $organizationId, ?callable $constrain = null): Collection
    {
        $query = self::query($organizationId)->orderBy('users.id');

        if ($constrain !== null) {
            $constrain($query);
        }

        return $query->get()
            ->map(fn (User $user) => self::userIn($user, $organizationId))
            ->filter()
            ->values();
    }

    /**
     * Run $work inside $organizationId as $user (every scoped query and new
     * tenant row confined to that organization), after checking the
     * membership. Older cores have no organization context: there $work just
     * runs with the user as given.
     *
     * @template T
     *
     * @param  callable(User): T  $work
     * @return T
     */
    public static function runAs(User $user, int $organizationId, callable $work): mixed
    {
        if (class_exists(self::ACTIVE)) {
            return app(self::ACTIVE)->runAs($user, $organizationId, [], $work);
        }

        return $work($user);
    }
}
