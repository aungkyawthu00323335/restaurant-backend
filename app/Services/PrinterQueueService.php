<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\PrintLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PrinterQueueService
{
    public function sendToPrinter(
        Printer $printer,
        string $formattedPayload,
        string $rawText = '',
        string $documentType = 'order',
        ?int $orderId = null,
        bool $isReprint = false
    ): bool {
        return $this->executeSocketSend($printer, $formattedPayload, $rawText, $documentType, $orderId, $isReprint);
    }

    protected function executeSocketSend(
        Printer $printer,
        string $formattedPayload,
        string $rawText = '',
        string $documentType = 'order',
        ?int $orderId = null,
        bool $isReprint = false
    ): bool {
        $maxRetries = 2;
        $connected = false;
        $socket = null;
        $errstr = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $errno = 0;
            $errstr = '';
            $socket = @fsockopen($printer->ip_address, (int) $printer->port, $errno, $errstr, 0.2);

            if ($socket) {
                $connected = true;
                break;
            }

            // Wait 20ms before retrying
            usleep(20000);
        }

        if ($connected && $socket) {
            stream_set_timeout($socket, 2);
            stream_set_blocking($socket, true);
            @fwrite($socket, $formattedPayload);
            usleep(50000); // 50ms pause to ensure buffer transmission before closing socket
            @fclose($socket);

            if ($orderId) {
                PrintLog::query()->create([
                    'document_type' => $documentType,
                    'order_id' => $orderId,
                    'printer_id' => $printer->id,
                    'print_status' => 'success',
                    'is_reprint' => $isReprint,
                    'copy_count' => $printer->copies ?? 1,
                    'printed_by' => auth()->id() ?? 'System',
                    'printed_at' => Carbon::now(),
                ]);
            }

            return true;
        }

        // Log print failure fallback
        try {
            $logPath = storage_path('logs/prints');
            if (! is_dir($logPath)) {
                @mkdir($logPath, 0755, true);
            }
            $filename = $logPath.'/printer_'.preg_replace('/[^a-zA-Z0-9]/', '_', $printer->name)
                .'_'.Carbon::now()->format('Ymd_His').'.txt';
            file_put_contents($filename, $rawText);
        } catch (\Exception $ex) {
            Log::warning('Could not write fallback log: '.$ex->getMessage());
        }

        if ($orderId) {
            PrintLog::query()->create([
                'document_type' => $documentType,
                'order_id' => $orderId,
                'printer_id' => $printer->id,
                'print_status' => 'failed',
                'error_message' => "Cannot connect to printer {$printer->name} ({$printer->ip_address}:{$printer->port}): {$errstr}",
                'is_reprint' => $isReprint,
                'copy_count' => $printer->copies ?? 1,
                'printed_by' => auth()->id() ?? 'System',
                'printed_at' => Carbon::now(),
            ]);
        }

        Log::error("Printer socket connection failed after {$maxRetries} attempts for {$printer->name} ({$printer->ip_address}:{$printer->port}): {$errstr}");

        return false;
    }
}
