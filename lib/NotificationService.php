<?php
declare(strict_types=1);

class NotificationService
{
    public function __construct(
        private readonly ?string $lineToken,
        private readonly string  $notifyMode = 'failure_only',
    ) {}

    public function notifyBackupSuccess(int $jobId, string $jobName, int $durationSeconds, int $sizeBytes): void
    {
        if ($this->notifyMode !== 'all') {
            return;
        }
        $size = $this->formatBytes($sizeBytes);
        $this->send("✅ BRS Backup สำเร็จ\nJob: $jobName (ID: $jobId)\nขนาด: $size\nใช้เวลา: {$durationSeconds}s");
    }

    public function notifyBackupFailure(int $jobId, string $jobName, string $error): void
    {
        $this->send("❌ BRS Backup ล้มเหลว\nJob: $jobName (ID: $jobId)\nError: $error");
    }

    public function notifyRestoreExecuted(int $jobId, string $jobName, string $mode, string $initiatedBy): void
    {
        $this->send("⚠️ BRS Restore ดำเนินการ\nJob: $jobName (ID: $jobId)\nMode: $mode\nBy: $initiatedBy");
    }

    public function notifyDiskLow(string $targetName, int $freeBytes): void
    {
        $free = $this->formatBytes($freeBytes);
        $this->send("⚠️ BRS ดิสก์เหลือน้อย\nTarget: $targetName\nพื้นที่ว่าง: $free");
    }

    private function send(string $message): void
    {
        if (empty($this->lineToken)) {
            return;
        }
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Authorization: Bearer {$this->lineToken}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['message' => "\n" . $message]),
                'timeout' => 10,
            ],
        ]);
        @file_get_contents('https://notify-api.line.me/api/notify', false, $context);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)   return round($bytes / 1048576, 2) . ' MB';
        return round($bytes / 1024, 2) . ' KB';
    }
}
