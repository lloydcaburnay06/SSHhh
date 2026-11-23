<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Whose data the current request (or CLI loop iteration) works on.
 *
 *  - owner: the user whose workspace (projects, SSH profiles, logs, settings) is in use.
 *  - actor: the signed-in user doing it. Equal to the owner, except when a super admin
 *    has stepped into another user's workspace; event logs then record the admin.
 *
 * Every user-data query in app/Services is scoped to owner(). If no workspace has been
 * set, owner() throws rather than letting an unscoped query run (fail closed).
 */
final class Workspace
{
    private ?int $ownerId = null;

    private ?int $actorId = null;

    public function set(int $ownerId, ?int $actorId = null): void
    {
        if ($ownerId <= 0) {
            throw new RuntimeException('Invalid workspace owner.');
        }
        $this->ownerId = $ownerId;
        $this->actorId = $actorId ?? $ownerId;
    }

    public function clear(): void
    {
        $this->ownerId = null;
        $this->actorId = null;
    }

    public function isSet(): bool
    {
        return $this->ownerId !== null;
    }

    public function owner(): int
    {
        return $this->ownerId ?? throw new RuntimeException('No workspace selected for this request.');
    }

    public function actor(): ?int
    {
        return $this->actorId;
    }

    /** A super admin working inside someone else's workspace. */
    public function isImpersonating(): bool
    {
        return $this->ownerId !== null && $this->actorId !== null && $this->ownerId !== $this->actorId;
    }

    /**
     * Runs $fn inside $ownerId's workspace, then restores the previous one
     * (CLI loops, webhooks).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function runAs(int $ownerId, callable $fn, ?int $actorId = null): mixed
    {
        [$prevOwner, $prevActor] = [$this->ownerId, $this->actorId];
        $this->set($ownerId, $actorId);
        try {
            return $fn();
        } finally {
            $this->ownerId = $prevOwner;
            $this->actorId = $prevActor;
        }
    }
}
