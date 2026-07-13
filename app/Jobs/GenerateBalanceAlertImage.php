<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\{Http, Log, Storage};
use Symfony\Component\Process\Process;

class GenerateBalanceAlertImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;
    public int $timeout = 60;

    private array $snapshot;
    private string $webhookUrl;

    public function __construct(array $snapshot, string $webhookUrl)
    {
        $this->snapshot = $snapshot;
        $this->webhookUrl = $webhookUrl;
    }

    public function handle(): void
    {
        $tmpDir = sys_get_temp_dir();
        $jsonPath = tempnam($tmpDir, 'bal_alert_') . '.json';
        $pngPath = tempnam($tmpDir, 'bal_alert_') . '.png';

        try {
            file_put_contents($jsonPath, json_encode($this->snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $pythonScript = base_path('tools/render_balance_alert_table.py');
            if (!file_exists($pythonScript)) {
                throw new \RuntimeException('Render script missing: ' . $pythonScript);
            }

            $pythonBin = $this->resolvePythonBinary();
            $processOutput = '';

            if (class_exists(Process::class)) {
                $process = new Process([$pythonBin, $pythonScript, $jsonPath, $pngPath]);
                $process->setTimeout(30);
                $process->run();
                $processOutput = $process->getOutput() . $process->getErrorOutput();

                if (!$process->isSuccessful()) {
                    throw new \RuntimeException('Image renderer failed: ' . $processOutput);
                }
            } else {
                $cmd = '"' . str_replace('"', '\\"', $pythonBin) . '" ' . escapeshellarg($pythonScript) . ' ' . escapeshellarg($jsonPath) . ' ' . escapeshellarg($pngPath) . ' 2>&1';
                $processOutput = shell_exec($cmd);

                if (!file_exists($pngPath) || filesize($pngPath) === 0) {
                    throw new \RuntimeException('Image renderer failed (shell): ' . $processOutput);
                }
            }

            if (!file_exists($pngPath) || filesize($pngPath) === 0) {
                throw new \RuntimeException('Generated PNG missing or empty: ' . $processOutput);
            }

            $this->sendToWebhook($pngPath);

        } catch (\Throwable $e) {
            Log::error('GenerateBalanceAlertImage job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw to trigger retry
        } finally {
            @unlink($jsonPath);
            @unlink($pngPath);
        }
    }

    private function sendToWebhook(string $pngPath): void
    {
        $levelMap = [
            'force' => '强制平衡',
            'rebalance' => '执行平衡',
            'prepare' => '准备资金',
            'none' => '无需提醒',
        ];
        $level = (string) ($this->snapshot['level'] ?? 'none');
        $levelText = $levelMap[$level] ?? $level;
        $windowText = (bool) data_get($this->snapshot, 'window.in_rebalance_window', false) ? '在平衡窗口' : '未在平衡窗口';
        $date = substr((string) ($this->snapshot['now'] ?? now()->toDateTimeString()), 0, 10);
        $maxDev = number_format((float) ($this->snapshot['portfolio']['max_deviation_pct'] ?? 0), 2);

        $content = implode("\n", [
            '【资产平衡提醒】',
            '等级: ' . $levelText,
            '平衡时机: ' . $windowText,
            '日期: ' . $date,
            '最大偏离: ' . $maxDev . '%',
            '',
            '偏离明细:',
        ]);

        $res = Http::timeout(30)
            ->attach('file', file_get_contents($pngPath), 'balance_alert.png')
            ->post($this->webhookUrl, ['content' => $content]);

        if (!$res->successful()) {
            throw new \RuntimeException('Webhook returned ' . $res->status() . ': ' . $res->body());
        }

        Log::info('Balance alert image sent successfully', [
            'level' => $level,
            'webhook' => parse_url($this->webhookUrl, PHP_URL_HOST) ?? 'unknown',
        ]);
    }

    private function resolvePythonBinary(): string
    {
        $envPython = trim((string) env('PYTHON_BIN', ''));
        if ($envPython !== '' && file_exists($envPython)) {
            return $envPython;
        }

        $candidates = [
            base_path('.venv/Scripts/python.exe'),
            'C:\\Users\\hosha\\Desktop\\crypto-tracker\\.venv\\Scripts\\python.exe',
            'C:\\Python312\\python.exe',
            'C:\\Program Files\\Python312\\python.exe',
            'C:\\Program Files\\Python311\\python.exe',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        $whereOutput = @shell_exec('where.exe python 2>NUL');
        if (is_string($whereOutput) && trim($whereOutput) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($whereOutput));
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && file_exists($line)) {
                    return $line;
                }
            }
        }

        return 'python';
    }
}