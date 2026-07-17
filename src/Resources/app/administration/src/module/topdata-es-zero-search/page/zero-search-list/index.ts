import template from './zero-search-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-zero-search-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'count',
            sortDirection: 'DESC',
            limit: 25,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_es_zero_search');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('topdata-es-zero-search.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('topdata-es-zero-search.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('topdata-es-zero-search.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('topdata-es-zero-search.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },
    },

    mounted() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            console.log('[zero-search-list] getList() called', {
                page: this.page,
                limit: this.limit,
                sortBy: this.sortBy,
                sortDirection: this.sortDirection,
                criteria,
            });

            this.repository.search(criteria).then((result) => {
                console.log('[zero-search-list] search SUCCESS', {
                    total: result.total,
                    length: result.length,
                    firstItem: result[0] || null,
                    resultType: typeof result,
                    result,
                });
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch((error) => {
                console.error('[zero-search-list] search FAILED', error);
                this.isLoading = false;
            });
        },

        onPageChange(params) {
            this.page = params.page;
            this.limit = params.limit;
            this.getList();
        },

        onSortColumn(column) {
            this.sortBy = column.dataIndex ?? column.property;
            this.sortDirection = column.sortDirection ?? 'ASC';
            this.getList();
        },
    },
});
