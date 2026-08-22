<?php

declare(strict_types=1);

namespace Saveurs\Support;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Stockage des pièces d'identité livreur — hors du webroot (api/storage/, sibling de public/,
 * jamais servi directement par Apache/PHP-FPM, voir README §5). Nom de fichier généré côté
 * serveur, jamais le nom original du client ; MIME validé sur le contenu réel, pas le
 * Content-Type déclaré par le navigateur.
 */
final class KycStorage
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];

    public static function baseDir(): string
    {
        $dir = __DIR__ . '/../../storage/kyc';

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    /** @throws \RuntimeException si le fichier est invalide (type/taille) */
    public static function save(int $driverUserId, UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Échec du téléversement');
        }

        if ($file->getSize() === null || $file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('Fichier trop volumineux (8 Mo max)');
        }

        $tmpPath = $file->getStream()->getMetadata('uri');
        $mime = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpPath);

        if (!isset(self::ALLOWED_EXTENSIONS[$mime])) {
            throw new \RuntimeException('Format non accepté (JPEG, PNG ou PDF uniquement)');
        }

        $filename = $driverUserId . '_' . bin2hex(random_bytes(8)) . '.' . self::ALLOWED_EXTENSIONS[$mime];
        $file->moveTo(self::baseDir() . '/' . $filename);

        return $filename;
    }

    public static function path(string $filename): string
    {
        return self::baseDir() . '/' . basename($filename);
    }

    public static function contentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::CONTENT_TYPES[$ext] ?? 'application/octet-stream';
    }
}
