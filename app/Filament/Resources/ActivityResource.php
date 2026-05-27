<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Activity Log';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Activity Log';

    protected static ?string $pluralModelLabel = 'Activity Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('log_name')
                    ->label('Log')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Action')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('causer.username')
                    ->label('By')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable(),

                // Optional: show IP if you stored it in properties.ip
                Tables\Columns\TextColumn::make('properties.ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn ($state) => $state ? class_basename($state) : '-')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subject_id')
                    ->label('Subject ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn () => Activity::query()
                        ->select('log_name')
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->all()
                    ),

                Tables\Filters\Filter::make('last_1_hour')
                    ->label('Last 1 hour')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subHour())),

                Tables\Filters\Filter::make('last_12_hours')
                    ->label('Last 12 hours')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subHours(12))),

                Tables\Filters\Filter::make('last_24_hours')
                    ->label('Last 24 hours')
                    ->query(fn ($query) => $query->where('created_at', '>=', now()->subDay())),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([])       // ✅ no view/edit/delete
            ->bulkActions([]);  // ✅ no bulk delete
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
            'index' => Pages\ListActivities::route('/'),
        ];
    }
}
