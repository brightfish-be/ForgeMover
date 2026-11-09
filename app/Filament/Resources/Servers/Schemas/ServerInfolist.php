<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\SitesRelationManager;
use App\Models\Server;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ServerInfolist
{
    /**
     * Forge-style detail layout (DESIGN.md §3.2): the sites list IS the main
     * column, all metadata lives in the card-less sidebar on the right.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(3)->schema([
                    Group::make([
                        Livewire::make(SitesRelationManager::class, fn (Server $record) => [
                            'ownerRecord' => $record,
                            'pageClass' => ViewServer::class,
                        ]),
                    ])->columnSpan(2),
                    Group::make([
                        Section::make('Details')
                            ->extraAttributes(['class' => 'sidebar-plain'])
                            ->schema([
                                TextEntry::make('forge_id')->label('ID')->inlineLabel()->placeholder('—'),
                                TextEntry::make('name')->label('ssh alias')->inlineLabel(),
                                TextEntry::make('db_version')
                                    ->label('Database')
                                    ->inlineLabel()
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (Server $record) => $record->db_version
                                        .($record->has_db ? '' : ' (stopped)')),
                                TextEntry::make('redis_version')
                                    ->label('Redis')
                                    ->inlineLabel()
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (Server $record) => $record->redis_version
                                        .($record->has_redis ? '' : ' (stopped)')),
                                TextEntry::make('region')->label('Region')->inlineLabel()->placeholder('—'),
                                TextEntry::make('php_default')
                                    ->label('PHP')
                                    ->inlineLabel()
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (Server $record) => new HtmlString(
                                        '<strong>'.e($record->php_default).'</strong>'
                                        .(($others = array_diff($record->php_versions ?? [], [preg_replace('/^(\d+\.\d+).*/', '$1', $record->php_default)]))
                                            ? ' <span class="text-gray-400">· '.e(implode(' · ', $others)).'</span>'
                                            : '')
                                    ))
                                    ->html(),
                                TextEntry::make('os_version')
                                    ->label('Ubuntu')
                                    ->inlineLabel()
                                    ->badge()
                                    ->color(fn (Server $record) => ($record->osEol()['eol'] ?? false) ? 'danger' : 'success')
                                    ->tooltip(fn (Server $record) => ($eol = $record->osEol())
                                        ? ($eol['eol'] ? "EOL since {$eol['date']}" : "EOL {$eol['date']}") : null),
                                TextEntry::make('size')->label('Size')->inlineLabel()->placeholder('—'),
                                TextEntry::make('created_at')->label('Created')->inlineLabel()->date('M j, Y'),
                            ]),
                        Section::make('Networking')
                            ->extraAttributes(['class' => 'sidebar-plain'])
                            ->schema([
                                TextEntry::make('ip_address')->label('Public IP')->inlineLabel()->placeholder('—'),
                            ]),
                        Section::make('Health')
                            ->extraAttributes(['class' => 'sidebar-plain'])
                            ->schema([
                                TextEntry::make('disk_pct')
                                    ->label('Disk')
                                    ->inlineLabel()
                                    ->badge()
                                    ->color(fn (Server $record) => match (true) {
                                        $record->disk_pct >= 90 => 'danger',
                                        $record->disk_pct >= 75 => 'warning',
                                        default => 'success',
                                    })
                                    ->formatStateUsing(fn (Server $record) => "{$record->disk_used}/{$record->disk_total} ({$record->disk_pct}%)"),
                                TextEntry::make('uptime_s')
                                    ->label('Uptime')
                                    ->inlineLabel()
                                    ->formatStateUsing(fn (Server $record) => $record->uptimePretty())
                                    ->placeholder('—'),
                                TextEntry::make('sites_synced_at')
                                    ->label('Sites synced')
                                    ->inlineLabel()
                                    ->since()
                                    ->placeholder('never'),
                                TextEntry::make('facts_fetched_at')
                                    ->label('Facts probed')
                                    ->inlineLabel()
                                    ->since()
                                    ->placeholder('never'),
                            ]),
                    ])->columnSpan(1),
                ]),
            ]);
    }
}
