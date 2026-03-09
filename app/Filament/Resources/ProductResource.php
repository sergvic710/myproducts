<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shopping-cart';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('category_id')
                ->relationship('category', 'name')
                ->required(),
            Select::make('unit_id')
                ->relationship('unit', 'name')
                ->required(),
            TextInput::make('count')->numeric()->default(1),
            TextInput::make('product_link_shop')->maxLength(64),
            SpatieMediaLibraryFileUpload::make('image')
                ->collection('image')
                ->image(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            SpatieMediaLibraryImageColumn::make('image')->collection('image'),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('category.name')->sortable(),
            TextColumn::make('unit.name')->sortable(),
            TextColumn::make('count')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit'   => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
