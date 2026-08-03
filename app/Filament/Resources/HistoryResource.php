<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HistoryResource\Pages;
use App\Models\History;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HistoryResource extends Resource
{
    protected static ?string $model = History::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->relationship('product', 'name')
                ->searchable()
                ->required(),
            Select::make('shop_id')
                ->relationship('shop', 'name')
                ->required(),
            DatePicker::make('date')->required(),
            TextInput::make('price')->numeric()->required(),
            TextInput::make('amount')->numeric()->required(),
            TextInput::make('total')->numeric(),
            Toggle::make('is_discount')->live(),
            TextInput::make('discount_price')
                ->numeric()
                ->visible(fn (Get $get): bool => (bool) $get('is_discount')),
            TextInput::make('filename')->maxLength(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date()->sortable(),
                TextColumn::make('product.name')->searchable()->sortable(),
                TextColumn::make('shop.name')->sortable(),
                TextColumn::make('amount')->sortable(),
                TextColumn::make('price')->money('EUR')->sortable(),
                IconColumn::make('is_discount')->boolean(),
                TextColumn::make('discount_price')->money('EUR'),
                TextColumn::make('total')->money('EUR')->sortable(),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('shop')
                    ->relationship('shop', 'name'),
                Filter::make('date')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'], fn ($q, $date) => $q->whereDate('date', '<=', $date));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListHistories::route('/'),
            'create' => Pages\CreateHistory::route('/create'),
            'edit'   => Pages\EditHistory::route('/{record}/edit'),
        ];
    }
}
