<?php
declare(strict_types=1);

use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveAdapter implements StorageAdapterInterface
{
    private Drive $driveService;
    private string $folderId;
    private bool $useSharedDrive;

    public function __construct(array $config, ?EncryptionService $encryption = null)
    {
        $saJson = $config['service_account_json_encrypted'] ?? $config['service_account_json'] ?? '';
        if ($encryption && isset($config['service_account_json_encrypted'])) {
            $saJson = $encryption->decryptString($config['service_account_json_encrypted'], 'credential');
        }

        $this->folderId      = $config['shared_drive_folder_id'];
        $this->useSharedDrive = (bool) ($config['use_shared_drive'] ?? false);

        $client = new GoogleClient();
        $client->setAuthConfig(json_decode($saJson, true));
        $client->setScopes([Drive::DRIVE]);
        $this->driveService = new Drive($client);
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        $filename = basename($remotePath);
        $meta = new DriveFile(['name' => $filename, 'parents' => [$this->folderId]]);
        $params = ['data' => file_get_contents($localPath), 'mimeType' => 'application/octet-stream', 'uploadType' => 'multipart'];
        if ($this->useSharedDrive) {
            $params['supportsAllDrives'] = true;
        }
        $this->driveService->files->create($meta, $params);
        return true;
    }

    public function download(string $remotePath, string $localPath): bool
    {
        $fileId = $this->findFileId(basename($remotePath));
        if (!$fileId) return false;
        $params = $this->useSharedDrive ? ['supportsAllDrives' => true] : [];
        $response = $this->driveService->files->get($fileId, array_merge($params, ['alt' => 'media']));
        file_put_contents($localPath, $response->getBody()->getContents());
        return true;
    }

    public function delete(string $remotePath): bool
    {
        $fileId = $this->findFileId(basename($remotePath));
        if (!$fileId) return false;
        $params = $this->useSharedDrive ? ['supportsAllDrives' => true] : [];
        $this->driveService->files->delete($fileId, $params);
        return true;
    }

    public function exists(string $remotePath): bool
    {
        return (bool) $this->findFileId(basename($remotePath));
    }

    public function listFiles(string $prefix = ''): array
    {
        $q = "'{$this->folderId}' in parents and trashed = false";
        if ($prefix) $q .= " and name contains '$prefix'";
        $params = ['q' => $q, 'fields' => 'files(id,name)'];
        if ($this->useSharedDrive) {
            $params += ['includeItemsFromAllDrives' => true, 'supportsAllDrives' => true, 'corpora' => 'drive', 'driveId' => $this->folderId];
        }
        $result = $this->driveService->files->listFiles($params);
        return array_column($result->getFiles(), 'name');
    }

    public function getFreeSpace(): ?int
    {
        return null;
    }

    public function testConnection(): array
    {
        try {
            $this->listFiles('.brs_test');
            return ['status' => 'success', 'message' => 'Google Drive folder accessible'];
        } catch (\Exception $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    private function findFileId(string $filename): ?string
    {
        $q = "'{$this->folderId}' in parents and name = '$filename' and trashed = false";
        $params = ['q' => $q, 'fields' => 'files(id)'];
        if ($this->useSharedDrive) {
            $params += ['includeItemsFromAllDrives' => true, 'supportsAllDrives' => true];
        }
        $result = $this->driveService->files->listFiles($params);
        $files  = $result->getFiles();
        return $files ? $files[0]->getId() : null;
    }
}
