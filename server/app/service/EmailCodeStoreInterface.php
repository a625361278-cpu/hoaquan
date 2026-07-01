<?php

namespace app\service;

interface EmailCodeStoreInterface
{
    public function assertCanSend(string $email, string $purpose = 'register'): void;

    public function store(string $email, string $code, string $purpose = 'register'): void;

    public function verify(string $email, string $code, string $purpose = 'register'): void;
}
