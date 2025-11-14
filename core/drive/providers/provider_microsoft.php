<?php
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;

class MicrosoftDriveProvider {
    public static function initClient(?array $credentials = null): Graph {
        $graph = new Graph();
        $graph->setAccessToken($credentials['access_token'] ?? MS_ACCESS_TOKEN);
        return $graph;
    }

    public static function createFolder(Graph $graph, string $name, ?string $parentId = null): string {
        $endpoint = $parentId ? "/me/drive/items/$parentId/children" : "/me/drive/root/children";
        $data = [
            'name' => $name,
            'folder' => new \stdClass(),
            '@microsoft.graph.conflictBehavior' => 'rename'
        ];
        $resp = $graph->createRequest("POST", $endpoint)->attachBody($data)->execute()->getBody();
        $obj = json_decode($resp, true);
        return $obj['id'] ?? '';
    }

    public static function uploadFile(Graph $graph, string $filePath, string $fileName, string $mimeType, string $folderId): string {
        $content = file_get_contents($filePath);
        $endpoint = "/me/drive/items/$folderId:/$fileName:/content";
        $resp = $graph->createRequest("PUT", $endpoint)->attachBody($content)->execute()->getBody();
        $obj = json_decode($resp, true);
        return $obj['id'] ?? '';
    }

    public static function replaceFile(Graph $graph, string $fileId, string $filePath, string $mimeType): bool {
        $content = file_get_contents($filePath);
        $graph->createRequest("PUT", "/me/drive/items/$fileId/content")
              ->attachBody($content)
              ->execute();
        return true;
    }

    public static function renameFile(Graph $graph, string $fileId, string $newName): bool {
        $graph->createRequest("PATCH", "/me/drive/items/$fileId")
              ->attachBody(['name' => $newName])
              ->execute();
        return true;
    }

    public static function getFileInfo(Graph $graph, string $fileId): array {
        $resp = $graph->createRequest("GET", "/me/drive/items/$fileId")->execute()->getBody();
        return json_decode($resp, true);
    }

    public static function deleteFile(Graph $graph, string $fileId): bool {
        $graph->createRequest("DELETE", "/me/drive/items/$fileId")->execute();
        return true;
    }
}
