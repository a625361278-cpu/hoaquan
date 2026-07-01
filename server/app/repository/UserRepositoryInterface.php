<?php

namespace app\repository;

interface UserRepositoryInterface
{
    public function findActiveByAccount(string $account): ?array;

    public function findActiveByAccountAndEmail(string $account, string $email): ?array;

    public function findActiveById(int $id): ?array;

    public function accountExists(string $account): bool;

    public function emailExists(string $email): bool;

    public function create(string $account, string $email, string $nickname, string $passwordHash): array;

    public function updatePasswordHash(int $id, string $passwordHash): void;
}
