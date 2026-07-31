<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'number'], true)
                    ? $request->string('sort_col')->toString()
                    : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Customer::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
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
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:60'],
            'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);
        $payload['number'] = $this->nextNumber();

        return response()->json(Customer::create($payload), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'string', 'email', 'max:160'],
            'phone' => ['required', 'string', 'max:60'],
            'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);

        $customer->update($payload);

        return response()->json($customer->refresh());
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    public function downloadImportTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="customers_import_template.csv"',
        ];

        $columns = ['name', 'phone', 'email', 'birthday', 'address', 'city', 'state', 'postal_code', 'country', 'note'];
        
        $output = "\xEF\xBB\xBF" . implode(',', $columns) . "\n";
        $output .= "David Smith,09987654321,david@example.com,1990-05-15,456 Market Road,Yangon,Yangon,11181,Myanmar,VIP Customer\n";
        $output .= "Sarah Connor,09123456789,sarah@example.com,1992-08-20,789 Broad Ave,Mandalay,Mandalay,05011,Myanmar,Regular Customer\n";

        return response($output, 200, $headers);
    }

    public function exportExcel(Request $request)
    {
        $customers = Customer::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')->get();
        $columns = ['No', 'Customer Name', 'Customer Number', 'Phone', 'Email', 'Birthday', 'Address', 'City', 'State', 'Postal Code', 'Country', 'Note'];
        $output = "\xEF\xBB\xBF" . implode(',', array_map(fn ($value) => $this->csvEscape($value), $columns)) . "\n";
        foreach ($customers as $customer) {
            $output .= implode(',', array_map(fn ($value) => $this->csvEscape($value), [
                $customer->id, $customer->name, $customer->number, $customer->phone, $customer->email,
                $customer->birthday, $customer->address, $customer->city, $customer->state,
                $customer->postal_code, $customer->country, $customer->note,
            ]))."\n";
        }
        return response($output, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="customers_export_'.date('Y-m-d').'.csv"']);
    }

    public function exportPdf(Request $request)
    {
        $customers = Customer::query()
            ->when($request->string('search')->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->orderByDesc('id')->get();
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Customer List</title><style>body{font-family:"Noto Sans Myanmar","Padauk","Segoe UI",sans-serif;margin:24px;color:#172033}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #cbd5e1;padding:7px;text-align:left;font-size:12px}th{background:#2563eb;color:#fff}tr:nth-child(even){background:#f8fafc}</style></head><body><h1>Customer List</h1><p>Total Customers: '.count($customers).'</p><table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>Birthday</th><th>Address</th></tr></thead><tbody>';
        foreach ($customers as $index => $customer) {
            $html .= '<tr><td>'.($index + 1).'</td><td>'.$this->htmlEscape($customer->name).'</td><td>'.$this->htmlEscape($customer->phone).'</td><td>'.$this->htmlEscape($customer->email ?: '-').'</td><td>'.$this->htmlEscape($customer->birthday ?: '-').'</td><td>'.$this->htmlEscape(implode(', ', array_filter([$customer->address, $customer->city, $customer->state, $customer->country]))).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'inline; filename="customers_report_'.date('Y-m-d').'.html"']);
    }

    public function importCustomers(Request $request): JsonResponse
    {
        $rows = $request->input('rows');
        if (empty($rows) && $request->hasFile('file')) {
            $rows = $this->parseCsvFile($request->file('file'));
        }

        if (empty($rows) || !is_array($rows)) {
            return response()->json(['message' => 'No valid customer rows or CSV file provided.'], 422);
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
                $birthday = !empty($row['birthday']) ? trim($row['birthday']) : null;

                Customer::updateOrCreate(
                    ['name' => $name],
                    [
                        'number' => $this->nextNumber(),
                        'email' => $row['email'] ?? null,
                        'phone' => $phone,
                        'birthday' => $birthday,
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
            'message' => "Successfully imported {$importedCount} customers.",
            'imported_count' => $importedCount,
            'errors' => $errors,
        ]);
    }

    private function nextNumber(): string
    {
        $model = Customer::class;
        $next = ($model::withTrashed()->max('id') ?? 0) + 1;

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
