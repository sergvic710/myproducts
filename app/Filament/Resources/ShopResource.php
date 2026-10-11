<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopResource\Pages;
use App\Models\Shop;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),

            // Wallet has no template API, so these three fields are the
            // transaction template named after this shop. Run
            // `php artisan wallet:accounts` for the exact names.
            TextInput::make('wallet_account')
                ->label('Wallet account')
                ->helperText('Leave empty to keep this shop out of Wallet.')
                ->maxLength(255),
            TextInput::make('wallet_category')
                ->label('Wallet category')
                ->maxLength(255),
            TextInput::make('wallet_payee')
                ->label('Wallet payee')
                ->placeholder('Defaults to the shop name')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('wallet_account')->label('Wallet account')->toggleable(),
            TextColumn::make('wallet_category')->label('Wallet category')->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShops::route('/'),
            'create' => Pages\CreateShop::route('/create'),
            'edit' => Pages\EditShop::route('/{record}/edit'),
        ];
    }
}
