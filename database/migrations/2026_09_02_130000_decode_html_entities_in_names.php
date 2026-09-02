<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Unit;
use Illuminate\Database\Migrations\Migration;

/**
 * Old form handling saved names HTML-escaped, sometimes twice:
 * "Dairy &amp; Eggs", "Grains &amp;amp; pasta". This decodes them back to
 * plain text and tidies the spacing around "&".
 *
 * A name with a real "&" in it (WC-PAPERI NICE&SOFT) decodes to itself, so it
 * is left alone. Only category labels get their "&" spacing tidied — product
 * names come from receipts and may contain a brand written without spaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Category names are hand-written labels, so the spacing around "&" is
        // tidied there. Product names come from receipts and may hold a brand
        // like "NICE&SOFT" — those are only unescaped, never re-spaced.
        foreach ([Category::class => true, Product::class => false, Shop::class => false, Unit::class => false] as $model => $fixSpacing) {
            foreach ($model::where('name', 'like', '%&%')->get() as $row) {
                $clean = $this->clean((string) $row->name, $fixSpacing);

                if ($clean !== $row->name) {
                    $row->name = $clean;
                    $row->save();
                }
            }
        }
    }

    public function down(): void
    {
        // Names cannot be re-escaped safely — a real "&" would be broken again.
    }

    private function clean(string $name, bool $fixSpacing): string
    {
        // Decode until it stops changing, so double escaping is handled too.
        for ($i = 0; $i < 5; $i++) {
            $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $name) {
                break;
            }

            $name = $decoded;
        }

        if ($fixSpacing) {
            // "Coffe &Tee" -> "Coffe & Tee"
            $name = (string) preg_replace('/\s*&\s*/u', ' & ', $name);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
};
