<?php

namespace Arafa\Payments\Support;

class AlRajhiEncryptionService
{
    private string $key;
    private string $iv;

    public function __construct(string $name, string $mode)
    {
        $this->key = config("payments.{$name}.{$mode}_encryption_key");
        $this->iv  = config("payments.{$name}.{$mode}_iv");
    }

    public function encrypt(string $data): string
    {
        return bin2hex(openssl_encrypt($data, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv));
    }

    public function decrypt(string $data): string|false
    {
        return openssl_decrypt(hex2bin($data), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $this->iv);
    }
}