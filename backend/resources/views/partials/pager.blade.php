@if($paginator->hasPages())
<nav class="pager" aria-label="{{ $label }}">
    @if($paginator->onFirstPage())
        <span class="pager-disabled" aria-disabled="true">السابق</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev">السابق</a>
    @endif
    <span>صفحة {{ $paginator->currentPage() }} من {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next">التالي</a>
    @else
        <span class="pager-disabled" aria-disabled="true">التالي</span>
    @endif
</nav>
@endif
