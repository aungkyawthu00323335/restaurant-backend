<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Supplier::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:60'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(Supplier::create($payload), 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json($supplier);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:60'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);

        $supplier->update($payload);

        return response()->json($supplier->refresh());
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    public function downloadImportTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="suppliers_import_template.csv"',
        ];

        $columns = ['name', 'contact_person', 'phone', 'email', 'address', 'city', 'state', 'postal_code', 'country', 'note'];
        
        $output = "\xEF\xBB\xBF" . implode(',', $columns) . "\n";
        $output .= "ABC Foods Co.,John Smith,09123456789,john@abcfoods.com,123 Main Street,Yangon,Yangon,11181,Myanmar,Main ingredient supplier\n";
        $output .= "Golden Poultry,Mary Sue,09987654321,mary@goldenpoultry.com,456 Market Road,Mandalay,Mandalay,05011,Myanmar,Fresh poultry supplier\n";

        return response($output, 200, $headers);
    }

    public function exportExcel(Request $request)
    {
        $suppliers = Supplier::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_person', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->get();

        $columns = ['No', 'Supplier Name', 'Supplier Number', 'Contact Person', 'Phone', 'Email', 'Address', 'City', 'State', 'Postal Code', 'Country', 'Note'];
        $output = "\xEF\xBB\xBF" . implode(',', array_map(fn ($value) => $this->csvEscape($value), $columns)) . "\n";
        foreach ($suppliers as $supplier) {
            $output .= implode(',', array_map(fn ($value) => $this->csvEscape($value), [
                $supplier->id, $supplier->name, $supplier->number, $supplier->contact_person,
                $supplier->phone, $supplier->email, $supplier->address, $supplier->city,
                $supplier->state, $supplier->postal_code, $supplier->country, $supplier->note,
            ]))."\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="suppliers_export_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $suppliers = Supplier::query()
            ->when($request->string('search')->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderByDesc('id')->get();
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Supplier List</title><style>body{font-family:"Noto Sans Myanmar","Padauk","Segoe UI",sans-serif;margin:24px;color:#172033}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:7px;text-align:left;font-size:12px}th{background:#2563eb;color:#fff}tr:nth-child(even){background:#f8fafc}</style></head><body><h1>Supplier List</h1><p>Total Suppliers: '.count($suppliers).'</p><table><thead><tr><th>#</th><th>Name</th><th>Contact Person</th><th>Phone</th><th>Email</th><th>Address</th><th>Status</th></tr></thead><tbody>';
        foreach ($suppliers as $index => $supplier) {
            $html .= '<tr><td>'.($index + 1).'</td><td>'.$this->htmlEscape($supplier->name).'</td><td>'.$this->htmlEscape($supplier->contact_person ?: '-').'</td><td>'.$this->htmlEscape($supplier->phone).'</td><td>'.$this->htmlEscape($supplier->email ?: '-').'</td><td>'.$this->htmlEscape(implode(', ', array_filter([$supplier->address, $supplier->city, $supplier->state, $supplier->country]))).'</td><td>Active</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'inline; filename="suppliers_report_'.date('Y-m-d').'.html"']);
    }

    public function importSuppliers(Request $request): JsonResponse
    {
        $rows = $request->input('rows');
        if (empty($rows) && $request->hasFile('file')) {
            $rows = $this->parseCsvFile($request->file('file'));
        }

        if (empty($rows) || !is_array($rows)) {
            return response()->json(['message' => 'No valid supplier rows or CSV file provided.'], 422);
        }

        $importedCount = 0;
        $errors = [];

        DB::transaction(function () use ($rows, &$importedCount, &$errors) {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 1;
                $name = trim($row['name'] ?? '');

                if ($name === '') {
                    $errors[] = "Row #{$rowNum}: Name is required.";
                    continue;
                }

                $phone = !empty($row['phone']) ? trim($row['phone']) : 'N/A';

                Supplier::updateOrCreate(
                    ['name' => $name],
                    [
                        'number' => $this->nextNumber(),
                        'contact_person' => $row['contact_person'] ?? null,
                        'email' => $row['email'] ?? null,
                        'phone' => $phone,
                        'address' => $row['address'] ?? null,
                        'city' => $row['city'] ?? null,
                        'state' => $row['state'] ?? null,
                        'postal_code' => $row['postal_code'] ?? null,
                        'country' => $row['country'] ?? null,
                        'note' => $row['note'] ?? null,
                    ]
                );

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Successfully imported {$importedCount} suppliers.",
            'imported_count' => $importedCount,
            'errors' => $errors,
        ]);
    }

    private function nextNumber(): string
    {
        $next = (Supplier::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }

    private function parseCsvFile($file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) return [];

        $headers = [];
        $rows = [];
        $rowNum = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNum++;
            if ($rowNum === 1) {
                $headers = array_map(fn($h) => strtolower(trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B")), $data);
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                if (isset($data[$i])) {
                    $row[$header] = trim($data[$i]);
                }
            }
            if (!empty($row['name'])) {
                $rows[] = $row;
            }
        }

        fclose($handle);
        return $rows;
    }

    private function csvEscape(mixed $value): string
    {
        return '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
    }

    private function htmlEscape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
