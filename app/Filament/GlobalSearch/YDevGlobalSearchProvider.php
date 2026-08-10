<?php

namespace App\Filament\GlobalSearch;

use App\Filament\Pages\Developer;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

class YDevGlobalSearchProvider extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        $builder = parent::getResults($query);

        if (strtolower(trim($query)) === 'ydev') {
            $builder ??= GlobalSearchResults::make();

            $builder->category('Developer', [
                new GlobalSearchResult(
                    title: '🛠 Developer Tools',
                    url: Developer::getUrl(),
                    details: ['Recompile assets, flush caches, and more'],
                ),
            ]);
        }

        return $builder;
    }
}
