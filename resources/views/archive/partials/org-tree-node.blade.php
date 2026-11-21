{{-- One branch of the self-building Org Chart. Needs $node (from OrgStructure::build()).
     Every person gets the same full-size card, side by side (explicit request,
     2026-11-20). A 'group' node is several people drawn as one box, all
     connected to what's under them (a team's "Show under these people"). --}}
@php
    $key = match ($node['type']) {
        'group' => 'g' . collect($node['members'])->map(fn ($m) => $m['model']->id)->join('-'),
        'team' => 't' . $node['model']->id,
        default => 'p' . $node['model']->id,
    };
    $count = count($node['children'] ?? []);
@endphp
<li data-branch="{{ $key }}" @if($node['isHead'] ?? false) data-head @endif>
    @if($node['type'] === 'group')
        <div class="org-group">
            @foreach($node['members'] as $member)
                @include('archive.partials.org-tree-card', ['node' => $member, 'compact' => false])
            @endforeach
        </div>
    @else
        @include('archive.partials.org-tree-card', ['node' => $node, 'compact' => false])
    @endif
    @if($count)
    <button type="button" data-collapse class="org-toggle no-print" title="Hide / show what's under this card" aria-expanded="true">
        <span class="org-toggle-less">▾ Hide</span><span class="org-toggle-more">▸ Show {{ $count }}</span>
    </button>
    <ul>
        @foreach($node['children'] as $k)
            @include('archive.partials.org-tree-node', ['node' => $k])
        @endforeach
    </ul>
    @endif
</li>
