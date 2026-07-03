<?php

namespace App\Services\Kyc;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Stores KYC identity documents (front/back/selfie) encrypted at rest on a
 * private, non-web-accessible disk. Contents are sealed with the app key
 * (AES-256 via Crypt) — the same scheme protecting the KYC PII columns — so a
 * leaked disk/backup never exposes raw identity documents.
 */
class KycDocumentStore
{
    private const DISK = 'kyc';

    /**
     * Encrypt the uploaded file's bytes and write them. Returns the stored path.
     * The `.enc` suffix marks the object as ciphertext, not a servable image.
     */
    public function store(int $userId, UploadedFile $file, string $name): string
    {
        $path = "kyc/{$userId}/{$name}.{$file->extension()}.enc";

        Storage::disk(self::DISK)->put($path, Crypt::encryptString($file->getContent()));

        return $path;
    }

    /** Decrypt and return the raw document bytes (e.g. for admin review). */
    public function retrieve(string $path): string
    {
        return Crypt::decryptString(Storage::disk(self::DISK)->get($path));
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
