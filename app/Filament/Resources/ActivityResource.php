<?php

namespace App\Filament\Resources;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Resources\ActivityResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string | \UnitEnum | null $navigationGroup = 'Activity';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Activity Log';

    protected static ?string $pluralModelLabel = 'Activity Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Action')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('causer.username')
                    ->label('By')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),

                // Optional: show IP if you stored it in properties.ip
                TextColumn::make('properties.ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn ($state) => $state ? class_basename($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn () => Activity::query()
                        ->select('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()
                    ),

                Filter::make('last_1_hour')
                    ->label('Last 1 hour')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subHour())),

                Filter::make('last_12_hours')
                    ->label('Last 12 hours')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subHours(12))),

                Filter::make('last_24_hours')
                    ->label('Last 24 hours')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subDay())),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([])       // ✅ no view/edit/delete
            ->toolbarActions([]);  // ✅ no bulk delete
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
