<?php
/**
 * drive_manager.php
 * -----------------
 * Capa unificada para operaciones con Google Drive y Microsoft OneDrive/SharePoint.
 * Permite cambiar dinámicamente el proveedor sin modificar la lógica del resto del sistema.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/platform.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/provider_google.php';
require_once __DIR__ . '/provider_microsoft.php';

class DriveManager {
    private string $provider;   // 'google' o 'microsoft'
    private $client;            // Cliente API actual (Google_Client o Microsoft Graph)
    private $service;           // Servicio activo (Google_Service_Drive o Graph)

    public function __construct(string $provider = 'google', ?array $credentials = null) {
        $this->provider = strtolower($provider);

        switch ($this->provider) {
            case 'google':
                $this->client = GoogleDriveProvider::initClient($credentials);
                $this->service = new Google_Service_Drive($this->client);
                break;

            case 'microsoft':
                $this->client = MicrosoftDriveProvider::initClient($credentials);
                $this->service = $this->client; // Graph ya actúa como servicio
                break;

            default:
                throw new Exception("Proveedor no soportado: {$this->provider}");
        }
    }

    /** Crear carpeta */
    public function createFolder(string $name, ?string $parentId = null): string {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::createFolder($this->service, $name, $parentId);
        }
        return MicrosoftDriveProvider::createFolder($this->service, $name, $parentId);
    }

    /** Subir archivo */
    public function uploadFile(string $filePath, string $fileName, string $mimeType, string $folderId): string {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::uploadFile($this->service, $filePath, $fileName, $mimeType, $folderId);
        }
        return MicrosoftDriveProvider::uploadFile($this->service, $filePath, $fileName, $mimeType, $folderId);
    }

    /** Reemplazar archivo */
    public function replaceFile(string $fileId, string $filePath, string $mimeType): bool {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::replaceFile($this->service, $fileId, $filePath, $mimeType);
        }
        return MicrosoftDriveProvider::replaceFile($this->service, $fileId, $filePath, $mimeType);
    }

    /** Renombrar archivo */
    public function renameFile(string $fileId, string $newName): bool {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::renameFile($this->service, $fileId, $newName);
        }
        return MicrosoftDriveProvider::renameFile($this->service, $fileId, $newName);
    }

    /** Obtener metadatos */
    public function getFileInfo(string $fileId): array {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::getFileInfo($this->service, $fileId);
        }
        return MicrosoftDriveProvider::getFileInfo($this->service, $fileId);
    }

    /** Eliminar archivo */
    public function deleteFile(string $fileId): bool {
        if ($this->provider === 'google') {
            return GoogleDriveProvider::deleteFile($this->service, $fileId);
        }
        return MicrosoftDriveProvider::deleteFile($this->service, $fileId);
    }
}
