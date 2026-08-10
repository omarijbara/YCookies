<div class="rounded-xl border border-white/5 bg-white/[0.02] overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-white/5 text-left text-xs uppercase tracking-wider text-gray-500">
                <th class="px-5 py-2.5 font-medium">Group</th>
                <th class="px-5 py-2.5 font-medium">Variable</th>
                <th class="px-5 py-2.5 font-medium">Value</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-white/5">
            @foreach ($this->getEnvVariables() as $var)
                <tr class="hover:bg-white/[0.02] transition">
                    <td class="px-5 py-2.5 text-gray-500 text-xs">{{ $var['group'] }}</td>
                    <td class="px-5 py-2.5 font-mono text-xs text-gray-300">{{ $var['key'] }}</td>
                    <td class="px-5 py-2.5 text-gray-400 text-xs">{{ $var['value'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
