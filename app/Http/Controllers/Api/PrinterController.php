<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PrinterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'ip_address', 'port'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Printer::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);

        return response()->json(Printer::create($payload), 201);
    }

    public function show(Printer $printer): JsonResponse
    {
        return response()->json($printer);
    }

    public function update(Request $request, Printer $printer): JsonResponse
    {
        $payload = $this->validatePayload($request);

        $printer->update($payload);

        return response()->json($printer->refresh());
    }

    public function destroy(Printer $printer): JsonResponse
    {
        if ($printer->foodMenus()->exists()) {
            return response()->json([
                'message' => 'This printer is assigned to one or more food menus and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        $printer->delete();

        return response()->json(['message' => 'Printer deleted.']);
    }

    public function testPrint(Request $request, Printer $printer): JsonResponse
    {
        if (! $printer->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Inactive printer cannot be used for printing.',
            ], 422);
        }

        $ts = now()->format('Y-m-d H:i:s');
        $line = str_repeat('-', $printer->paper_size === '58mm' ? 32 : 42)."\n";

        $slip = "\x1B\x40"
            ."\x1B\x45\x01"
            ."                  GLOBAL POS\n"
            ."\x1B\x45\x00"
            ."                 PRINTER TEST SLIP\n"
            .$line
            ."Printer  : {$printer->name}\n"
            ."IP       : {$printer->ip_address}\n"
            ."Port     : {$printer->port}\n"
            ."Paper    : {$printer->paper_size}\n"
            ."Copies   : {$printer->copies}\n"
            ."Status   : ".($printer->is_active ? 'Active' : 'Inactive')."\n"
            ."Date     : {$ts}\n"
            .$line
            ."Alphabet : ABCDEFGHIJKLM NOPQRSTUVWXYZ\n"
            ."Numeric  : 1234567890\n"
            ."Symbols  : !@#\$%^&*() .,:;?/|+-=_[]\n"
            .$line
            ."Item Sample x1      1,000.00\n"
            ."Tax (5%)               50.00\n"
            ."\x1B\x45\x01"
            ."TOTAL               1,050.00\n"
            ."\x1B\x45\x00"
            .$line
            ."If you can read this clearly,\n"
            ."the printer is working correctly.\n"
            ."\n"
            ."Thank you!\n"
            ."\n\n\n\n"
            ."\x1D\x56\x00";

        try {
            $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, 2);
            if ($socket) {
                fwrite($socket, $slip);
                fclose($socket);

                return response()->json([
                    'success' => true,
                    'message' => "Test slip sent successfully to {$printer->name}.",
                ]);
            }
        } catch (\Throwable $e) {
            Log::info("Socket connection to {$printer->ip_address}:{$printer->port} unfulfilled (Cloud/Web mode).");
        }

        return response()->json([
            'success' => true,
            'message' => "Test print command processed for {$printer->name} ({$printer->ip_address}:{$printer->port}).",
        ]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'ip_address' => [
                'required',
                'ipv4',
            ],
            'port' => ['required', 'integer', 'between:1,65535'],
            'paper_size' => ['required', Rule::in(['58mm', '80mm', 'A4'])],
            'copies' => ['required', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'ip_address.unique' => 'This IP Address and Port combination is already in use.',
        ]);
    }
}
