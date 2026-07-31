<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ConsumptionUnit;
use App\Models\Customer;
use App\Models\Floor;
use App\Models\FoodMenu;
use App\Models\FoodMenuUnit;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\RestaurantTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $outlet = Location::first();
        if (!$outlet) {
            $outlet = Location::create([
                'name' => 'Main Restaurant',
                'number' => '001',
                'email' => 'contact@mainresto.com',
                'phone' => '+1-555-1001',
                'address' => '100 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
                'opening_time' => '08:00',
                'closing_time' => '23:00',
                'tax_identification_number' => 'TAX-MN-001',
                'is_head_office' => true,
                'is_active' => true,
            ]);
        }
        $outletId = $outlet->id;

        // 1. Seed Active Printers (with IP 192.168.1.100)
        $printerData = [
            ['name' => 'Kitchen Printer', 'ip_address' => '192.168.1.100', 'port' => 9100, 'paper_size' => '80mm', 'is_active' => true],
            ['name' => 'Bar Printer', 'ip_address' => '192.168.1.100', 'port' => 9100, 'paper_size' => '80mm', 'is_active' => true],
            ['name' => 'Cashier Printer', 'ip_address' => '192.168.1.100', 'port' => 9100, 'paper_size' => '80mm', 'is_active' => true],
            ['name' => 'Grill Printer', 'ip_address' => '192.168.1.100', 'port' => 9100, 'paper_size' => '80mm', 'is_active' => true],
        ];
        $printers = [];
        foreach ($printerData as $pData) {
            $printers[] = Printer::firstOrCreate(
                ['name' => $pData['name']],
                $pData
            );
        }
        $defaultPrinterId = $printers[0]->id ?? 1;

        // 2. Seed 30 Payment Methods
        $paymentMethods = [
            'Cash', 'KBZPay', 'CB Pay', 'AYA Pay', 'WavePay',
            'uabpay', 'OnePay', 'Visa Card', 'MasterCard', 'UnionPay',
            'MPU Card', 'Credit Account', 'Bank Transfer', 'Alipay', 'WeChat Pay',
            'GrabPay', 'PromptPay', 'Cash on Delivery', 'Gift Voucher', 'Mobile Banking',
            'City Rewards', 'KBZ QR Pay', 'CB QR Pay', 'AYA QR Pay', 'Corporate Account',
            'Staff Account', 'VIP Privilege Card', 'Promo Voucher', 'Wallet Pay', 'Crypto Payment'
        ];
        foreach ($paymentMethods as $pmName) {
            PaymentMethod::firstOrCreate(
                ['name' => $pmName],
                [
                    'description' => $pmName . ' Payment Method',
                    'is_active' => true,
                ]
            );
        }

        // 3. Seed Categories & Units
        $catList = ['Fried & Snacks', 'Noodles & Soups', 'Curry & Rice', 'Traditional Dishes', 'Desserts & Beverages', 'Retail Products', 'Liquors'];
        $catModels = [];
        $pCatModels = [];
        foreach ($catList as $idx => $cName) {
            $catModels[] = Category::firstOrCreate(
                ['name' => $cName],
                [
                    'slug' => Str::slug($cName),
                    'description' => $cName . ' Category',
                    'is_active' => true,
                ]
            );

            $pCatModels[] = ProductCategory::firstOrCreate(
                ['name' => $cName],
                [
                    'code' => 'PCAT-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                    'description' => $cName . ' Category',
                    'is_active' => true,
                ]
            );
        }

        $fmUnit = FoodMenuUnit::firstOrCreate(
            ['name' => 'Portion'],
            ['description' => 'Single Portion Unit', 'is_active' => true]
        );
        $unitId = $fmUnit->id;

        $pUnit = ProductUnit::firstOrCreate(
            ['name' => 'Piece'],
            ['code' => 'PCS', 'description' => 'Piece Unit', 'is_active' => true]
        );
        $pUnitId = $pUnit->id;

        // 4. Seed 30 Food Menus
        $foodMenuList = [
            ['name' => 'ထမင်းကြော်', 'price' => 3500, 'cat_idx' => 0],
            ['name' => 'ခေါက်ဆွဲကြော်', 'price' => 3500, 'cat_idx' => 0],
            ['name' => 'ကြာဆံကြော်', 'price' => 3500, 'cat_idx' => 0],
            ['name' => 'ထမင်းသုပ်', 'price' => 3000, 'cat_idx' => 3],
            ['name' => 'လက်ဖက်သုပ်', 'price' => 2500, 'cat_idx' => 3],
            ['name' => 'ဝက်ကောက်လုံးကြော်', 'price' => 5000, 'cat_idx' => 0],
            ['name' => 'ကြက်သားဟင်း', 'price' => 4500, 'cat_idx' => 2],
            ['name' => 'ဝက်သားဟင်း', 'price' => 5000, 'cat_idx' => 2],
            ['name' => 'အမဲသားဟင်း', 'price' => 6000, 'cat_idx' => 2],
            ['name' => 'ငါးကြော်ဆီပြန်', 'price' => 4500, 'cat_idx' => 2],
            ['name' => 'ပုစွန်ဆီပြန်', 'price' => 6500, 'cat_idx' => 2],
            ['name' => 'တုံယမ်းစွပ်ပြုတ်', 'price' => 5500, 'cat_idx' => 1],
            ['name' => 'ကြက်သောင်းစွပ်ပြုတ်', 'price' => 4500, 'cat_idx' => 1],
            ['name' => 'အကြော်စုံ', 'price' => 3000, 'cat_idx' => 0],
            ['name' => 'မုန့်ဟင်းခါး', 'price' => 2500, 'cat_idx' => 3],
            ['name' => 'အုန်းနို့ခေါက်ဆွဲ', 'price' => 3000, 'cat_idx' => 3],
            ['name' => 'ပလာတာ', 'price' => 1500, 'cat_idx' => 0],
            ['name' => 'ငါးအကင်', 'price' => 8000, 'cat_idx' => 2],
            ['name' => 'မာလာရှမ်းကော', 'price' => 9000, 'cat_idx' => 2],
            ['name' => 'Tom Yum Soup Special', 'price' => 7500, 'cat_idx' => 1],
            ['name' => 'Crispy Fried Chicken', 'price' => 5000, 'cat_idx' => 0],
            ['name' => 'Double Cheeseburger', 'price' => 6500, 'cat_idx' => 0],
            ['name' => 'Spaghetti Carbonara', 'price' => 7000, 'cat_idx' => 1],
            ['name' => 'Beef Ribeye Steak', 'price' => 18000, 'cat_idx' => 2],
            ['name' => 'BBQ Pork Ribs', 'price' => 15000, 'cat_idx' => 2],
            ['name' => 'Club Sandwich', 'price' => 4000, 'cat_idx' => 0],
            ['name' => 'Fresh Mango Smoothie', 'price' => 3500, 'cat_idx' => 4],
            ['name' => 'Iced Americano Coffee', 'price' => 3000, 'cat_idx' => 4],
            ['name' => 'Thai Milk Tea', 'price' => 2800, 'cat_idx' => 4],
            ['name' => 'Fresh Orange Juice', 'price' => 3500, 'cat_idx' => 4],
        ];

        foreach ($foodMenuList as $idx => $fData) {
            $catId = $catModels[$fData['cat_idx']]->id ?? $catModels[0]->id;
            FoodMenu::firstOrCreate(
                ['code' => 'FM-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $fData['name'],
                    'category_id' => $catId,
                    'printer_id' => $defaultPrinterId,
                    'unit_id' => $unitId,
                    'stock_deduction_method' => 'no_stock',
                    'dine_in_price' => $fData['price'],
                    'take_away_price' => $fData['price'],
                    'delivery_price' => $fData['price'] + 500,
                    'cost_per_unit' => $fData['price'] * 0.5,
                    'description' => $fData['name'] . ' Special Dish',
                    'is_active' => true,
                ]
            );
        }

        // 5. Seed 30 Retail Products
        $productList = [
            ['name' => 'Heineken Beer Bottle 640ml', 'price' => 4500, 'cat_idx' => 6],
            ['name' => 'Myanmar Beer Can 330ml', 'price' => 2500, 'cat_idx' => 6],
            ['name' => 'Dagon Extra Strong Beer', 'price' => 2800, 'cat_idx' => 6],
            ['name' => 'Coca Cola Can 330ml', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Pepsi Can 330ml', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Sprite Can 330ml', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Red Bull Energy Drink', 'price' => 2000, 'cat_idx' => 5],
            ['name' => 'Evian Natural Mineral Water', 'price' => 2500, 'cat_idx' => 5],
            ['name' => 'Alpine Purified Water 1L', 'price' => 800, 'cat_idx' => 5],
            ['name' => 'Nescafe Cold Coffee Can', 'price' => 1500, 'cat_idx' => 5],
            ['name' => 'Lipton Iced Green Tea', 'price' => 1500, 'cat_idx' => 5],
            ['name' => 'Lays Potato Chips Classic', 'price' => 2000, 'cat_idx' => 5],
            ['name' => 'Roasted Salted Peanuts 100g', 'price' => 1500, 'cat_idx' => 5],
            ['name' => 'Premium Cashew Nuts 150g', 'price' => 4500, 'cat_idx' => 5],
            ['name' => 'Cadbury Dairy Milk Chocolate', 'price' => 3000, 'cat_idx' => 5],
            ['name' => 'Wall\'s Ice Cream Cup', 'price' => 2000, 'cat_idx' => 5],
            ['name' => 'Mentos Fresh Chewing Gum', 'price' => 800, 'cat_idx' => 5],
            ['name' => 'Marlboro Red Cigarettes Pack', 'price' => 6000, 'cat_idx' => 5],
            ['name' => 'MEVIUS Option Purple Pack', 'price' => 6500, 'cat_idx' => 5],
            ['name' => 'Snickers Chocolate Energy Bar', 'price' => 1800, 'cat_idx' => 5],
            ['name' => 'Fanta Orange Can 330ml', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Malee Apple Juice 1L', 'price' => 4500, 'cat_idx' => 5],
            ['name' => 'Tipco Grape Juice 1L', 'price' => 4800, 'cat_idx' => 5],
            ['name' => 'Schweppes Tonic Water', 'price' => 1500, 'cat_idx' => 5],
            ['name' => 'Singha Soda Water Glass Bottle', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Blue Diamond Salted Almonds', 'price' => 5000, 'cat_idx' => 5],
            ['name' => 'Fruit Jelly Pack 200g', 'price' => 1800, 'cat_idx' => 5],
            ['name' => 'Dutch Mill Milk Box 250ml', 'price' => 1200, 'cat_idx' => 5],
            ['name' => 'Perrier Sparkling Water 330ml', 'price' => 3500, 'cat_idx' => 5],
            ['name' => 'Monster Energy Drink 500ml', 'price' => 3500, 'cat_idx' => 5],
        ];

        foreach ($productList as $idx => $pData) {
            $catId = $pCatModels[$pData['cat_idx']]->id ?? $pCatModels[5]->id;
            Product::firstOrCreate(
                ['code' => 'PRD-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $pData['name'],
                    'product_category_id' => $catId,
                    'product_unit_id' => $pUnitId,
                    'printer_id' => $defaultPrinterId,
                    'purchase_price_per_unit' => $pData['price'] * 0.6,
                    'sell_price_per_unit' => $pData['price'],
                    'description' => $pData['name'],
                    'is_active' => true,
                ]
            );
        }

        // 6. Seed 30 Customers
        $customerNames = [
            'U Aung Kyaw Thu', 'Daw Khin Sein', 'Mg Ko Ko', 'Ma Su Su', 'U Min Thant',
            'Daw Thin Thin', 'U Zaw Lin', 'Ma Aye Aye', 'Mg Naing Oo', 'Daw Nilar',
            'John Smith', 'Emily Davis', 'Michael Brown', 'Sarah Wilson', 'David Taylor',
            'U Than Sein', 'Daw Win Win', 'Mg Tun Tun', 'Ma May Phyo', 'U Myo Min',
            'Daw Myint Myint', 'Mg Thura', 'Ma Zin Mar', 'U Kyaw Win', 'Daw Aye Thida',
            'Robert Johnson', 'Jessica Martinez', 'Daniel Anderson', 'Sophia Thomas', 'Alex White'
        ];

        foreach ($customerNames as $idx => $cName) {
            $phone = '+95 9 ' . rand(200000000, 999999999);
            Customer::firstOrCreate(
                ['name' => $cName],
                [
                    'phone' => $phone,
                    'email' => strtolower(str_replace(' ', '', $cName)) . '@example.com',
                    'address' => 'No. ' . ($idx + 1) . ', Pyay Road, Yangon',
                ]
            );
        }

        // 7. Seed Floors & 100 Tables (All assigned to outlet_id)
        $floorData = [
            ['name' => 'Ground Floor', 'code' => 'FL-01', 'sort_order' => 1],
            ['name' => 'First Floor', 'code' => 'FL-02', 'sort_order' => 2],
            ['name' => 'Second Floor', 'code' => 'FL-03', 'sort_order' => 3],
            ['name' => 'Outdoor & VIP Garden', 'code' => 'FL-04', 'sort_order' => 4],
        ];

        $floors = [];
        foreach ($floorData as $fItem) {
            $floors[] = Floor::firstOrCreate(
                ['name' => $fItem['name']],
                [
                    'code' => $fItem['code'],
                    'location_id' => $outletId,
                    'sort_order' => $fItem['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        for ($t = 1; $t <= 100; $t++) {
            if ($t <= 25) {
                $flId = $floors[0]->id;
            } elseif ($t <= 50) {
                $flId = $floors[1]->id;
            } elseif ($t <= 75) {
                $flId = $floors[2]->id;
            } else {
                $flId = $floors[3]->id;
            }

            RestaurantTable::firstOrCreate(
                ['table_no' => 'T-' . str_pad($t, 3, '0', STR_PAD_LEFT)],
                [
                    'outlet_id' => $outletId,
                    'floor_id' => $flId,
                    'capacity' => rand(2, 8),
                    'status' => 'available',
                    'is_active' => true,
                ]
            );
        }
    }
}
