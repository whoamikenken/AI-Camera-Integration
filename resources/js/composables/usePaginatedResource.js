import { ref, reactive, computed, watch, onMounted } from 'vue';
import apiClient from '../api/client';

export function usePaginatedResource(endpointOrFetchFn, options = {}) {
    const {
        initialPage = 1,
        initialPerPage = 15,
        initialFilters = {},
        initialSort = { field: '', direction: 'desc' },
        debounceMs = 300,
        immediate = true,
        transform = null,
    } = options;

    const items = ref([]);
    const loading = ref(false);
    const error = ref(null);
    const currentPage = ref(initialPage);
    const perPage = ref(initialPerPage);
    const searchQuery = ref('');
    const filters = reactive({ ...initialFilters });
    const sort = reactive({ ...initialSort });

    const rawPagination = ref({
        current_page: initialPage,
        last_page: 1,
        per_page: initialPerPage,
        total: 0,
        from: 0,
        to: 0,
    });

    const pagination = computed(() => rawPagination.value);

    // Universal response parser
    function parseResponse(res) {
        if (!res) return { items: [], meta: {} };
        const raw = res.data !== undefined ? res.data : res;

        // 1. Wrapped ApiResponse where data contains a nested paginator: { success: true, data: { data: [...], current_page: ... } }
        if (raw && raw.data && typeof raw.data === 'object' && Array.isArray(raw.data.data)) {
            const pageData = raw.data;
            return {
                items: pageData.data,
                meta: {
                    current_page: pageData.current_page || 1,
                    last_page: pageData.last_page || 1,
                    per_page: pageData.per_page || perPage.value,
                    total: pageData.total !== undefined ? pageData.total : pageData.data.length,
                    from: pageData.from || 1,
                    to: pageData.to || pageData.data.length,
                }
            };
        }

        // 2. Standard Laravel Paginator or dual ApiResponse: { data: [...], current_page: ..., last_page: ..., total: ... }
        if (raw && Array.isArray(raw.data) && (raw.current_page !== undefined || raw.total !== undefined)) {
            return {
                items: raw.data,
                meta: {
                    current_page: raw.current_page || 1,
                    last_page: raw.last_page || 1,
                    per_page: raw.per_page || perPage.value,
                    total: raw.total !== undefined ? raw.total : raw.data.length,
                    from: raw.from || 1,
                    to: raw.to || raw.data.length,
                }
            };
        }

        // 3. API Resource Collection with meta: { data: [...], meta: { current_page: ..., ... } }
        if (raw && Array.isArray(raw.data) && raw.meta) {
            const metaObj = raw.meta.pagination || raw.meta;
            return {
                items: raw.data,
                meta: {
                    current_page: metaObj.current_page || 1,
                    last_page: metaObj.last_page || 1,
                    per_page: metaObj.per_page || perPage.value,
                    total: metaObj.total !== undefined ? metaObj.total : raw.data.length,
                    from: metaObj.from || 1,
                    to: metaObj.to || raw.data.length,
                }
            };
        }

        // 4. Flat Array: [ ... ] or { data: [ ... ] }
        const flatList = Array.isArray(raw) ? raw : (Array.isArray(raw.data) ? raw.data : []);
        return {
            items: flatList,
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: flatList.length || perPage.value,
                total: flatList.length,
                from: flatList.length > 0 ? 1 : 0,
                to: flatList.length,
            }
        };
    }

    async function fetch(page = currentPage.value, silent = false) {
        if (!silent) loading.value = true;
        error.value = null;

        try {
            const params = {
                page,
                per_page: perPage.value,
                ...filters,
            };

            if (searchQuery.value) {
                params.search = searchQuery.value;
            }
            if (sort.field) {
                params.sort_by = sort.field;
                params.sort_direction = sort.direction;
            }

            let res;
            if (typeof endpointOrFetchFn === 'function') {
                res = await endpointOrFetchFn(params);
            } else {
                res = await apiClient.get(endpointOrFetchFn, { params });
            }

            const parsed = parseResponse(res);
            let resultItems = parsed.items;
            if (typeof transform === 'function') {
                resultItems = resultItems.map(transform);
            }

            items.value = resultItems;
            currentPage.value = parsed.meta.current_page || page;
            rawPagination.value = parsed.meta;
        } catch (err) {
            error.value = err;
            console.error('usePaginatedResource fetch error:', err);
        } finally {
            if (!silent) loading.value = false;
        }
    }

    function changePage(page) {
        if (page >= 1 && page <= rawPagination.value.last_page) {
            currentPage.value = page;
            return fetch(page);
        }
    }

    function goToPage(page) {
        return changePage(page);
    }

    function nextPage() {
        if (currentPage.value < rawPagination.value.last_page) {
            return changePage(currentPage.value + 1);
        }
    }

    function prevPage() {
        if (currentPage.value > 1) {
            return changePage(currentPage.value - 1);
        }
    }

    function refresh(silent = false) {
        return fetch(currentPage.value, silent);
    }

    function setFilter(key, value) {
        filters[key] = value;
        currentPage.value = 1;
        return fetch(1);
    }

    function resetFilters() {
        Object.keys(filters).forEach(k => {
            filters[k] = initialFilters[k] !== undefined ? initialFilters[k] : '';
        });
        searchQuery.value = '';
        currentPage.value = 1;
        return fetch(1);
    }

    function mutate(mutator) {
        if (typeof mutator === 'function') {
            items.value = mutator(items.value);
        } else if (Array.isArray(mutator)) {
            items.value = mutator;
        }
    }

    let searchTimer = null;
    watch(searchQuery, () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentPage.value = 1;
            fetch(1);
        }, debounceMs);
    });

    if (immediate) {
        onMounted(() => fetch(currentPage.value));
    }

    return {
        items,
        loading,
        error,
        page: currentPage,
        currentPage,
        perPage,
        searchQuery,
        filters,
        sort,
        pagination,
        fetch,
        fetchData: fetch,
        changePage,
        goToPage,
        nextPage,
        prevPage,
        refresh,
        setFilter,
        resetFilters,
        mutate,
    };
}

export default usePaginatedResource;
