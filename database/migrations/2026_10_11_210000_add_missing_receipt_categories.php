<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * The parser is handed `Category::pluck('name')` as a JSON-schema enum, so it
 * can only return a category that already exists. Everything else falls to
 * `default` — and `default` had grown into the largest category of the month,
 * holding a bathroom cabinet, batteries, glue, a storage box, panty liners,
 * Coca-Cola and frozen gyoza. None of those was a misreading: the receipts were
 * legible and there was simply nowhere to put them.
 *
 * These five cover Lidl's non-food aisle and the three food groups the list was
 * missing. They do not fix the other half of `default`, which is product names
 * misread off photos — no category can hold "Duiery yityapa kankoroe".
 *
 * Existing products keep the category they were saved with; this only changes
 * what the parser can choose from here on.
 */
return new class extends Migration
{
    /** Categories that had nowhere to go, with the receipt lines that proved it. */
    private const ADDED = [
        'Household',            // Kylpyhuoneen kaappi, Säilytyslaatikko, UHU Liima, Tronic Alkaliparistot
        'Personal care',        // SiemprePikkuhousunsuoja, LIUKUVOIDE
        'Drinks',               // Coca-Cola Zero — "Coffe & Tee" was the only drink category
        'Snacks & Sweets',      // Alpenfeststyle jättisuukko, Hasselpähkinä
        'Frozen & ready meals', // Vitasia gyoza, Sol&Mar perunamunakas, Kartanon pinaattiohukainen
    ];

    /** Hand-typed labels the model is shown on every receipt. */
    private const RENAMED = [
        'Coffe & Tee' => 'Coffee & Tea',
        'Cans andJars' => 'Cans & Jars',
    ];

    public function up(): void
    {
        foreach (self::ADDED as $name) {
            Category::firstOrCreate(['name' => $name]);
        }

        foreach (self::RENAMED as $from => $to) {
            $this->rename($from, $to);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMED as $from => $to) {
            $this->rename($to, $from);
        }

        foreach (self::ADDED as $name) {
            $category = Category::where('name', $name)->first();

            // Only if nothing was filed under it — removing a category that
            // products point at would orphan them.
            if ($category && ! Product::where('category_id', $category->id)->exists()) {
                $category->delete();
            }
        }
    }

    private function rename(string $from, string $to): void
    {
        // Products link by id, so renaming is safe. Skip if the target already
        // exists, so re-running cannot merge two categories by accident.
        if (Category::where('name', $to)->exists()) {
            return;
        }

        Category::where('name', $from)->update(['name' => $to]);
    }
};
