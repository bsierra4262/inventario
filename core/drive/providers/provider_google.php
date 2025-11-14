<?php
class GoogleDriveProvider {
    public static function initClient(?array $credentials = null): Google_Client {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CLIENT_SECRET);
        $client->setRedirectUri(GOOGLE_REDIRECT_URI);
        $client->addScope([
            Google_Service_Drive::DRIVE,
            Google_Service_Drive::DRIVE_FILE,
            Google_Service_Drive::DRIVE_METADATA_READONLY
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');

        if ($credentials) {
            $client->setAccessToken($credentials);
        }
        return $client;
    }

    public static function createFolder($service, string $name, ?string $parentId = null): string {
        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);
        if ($parentId) {
            $fileMetadata->setParents([$parentId]);
        }
        $folder = $service->files->create($fileMetadata, ['fields' => 'id']);
        return $folder->id;
    }

    public static function uploadFile($service, string $filePath, string $fileName, string $mimeType, string $folderId): string {
        $fileMetadata = new Google_Service_Drive_DriveFile([
            'name' => $fileName,
            'parents' => [$folderId]
        ]);
        $content = file_get_contents($filePath);
        $file = $service->files->create($fileMetadata, [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart',
            'fields' => 'id'
        ]);
        return $file->id;
    }

    public static function replaceFile($service, string $fileId, string $filePath, string $mimeType): bool {
        $content = file_get_contents($filePath);
        $service->files->update($fileId, new Google_Service_Drive_DriveFile(), [
            'data' => $content,
            'mimeType' => $mimeType,
            'uploadType' => 'multipart'
        ]);
        return true;
    }

    public static function renameFile($service, string $fileId, string $newName): bool {
        $file = new Google_Service_Drive_DriveFile(['name' => $newName]);
        $service->files->update($fileId, $file);
        return true;
    }

    public static function getFileInfo($service, string $fileId): array {
        $file = $service->files->get($fileId, ['fields' => 'id, name, mimeType, size, modifiedTime']);
        return $file->toSimpleObject();
    }

    public static function deleteFile($service, string $fileId): bool {
        $service->files->delete($fileId);
        return true;
    }
}
