import template from './search-stats-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-search-stats-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'count',
            sortDirection: 'DESC',
            limit: 25,
            showResetModal: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdeh_search_stats');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'zeroCount',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnZeroCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'avgResultCount',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnAvgResultCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnCreatedAt'),
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

            this.repository.search(criteria).then((result) => {
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch(() => {
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

        onDownloadCsv() {
            const httpClient = Shopware.Application.getContainer('init').httpClient;
            httpClient.get('_action/topdata-elasticsearch-hacks-sw6/search-stats/export', {
                responseType: 'blob',
            }).then((response) => {
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'search-statistics.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.exportError'),
                });
            });
        },

        onReset() {
            this.showResetModal = true;
        },

        onConfirmReset() {
            this.showResetModal = false;
            this.isLoading = true;

            const httpClient = Shopware.Application.getContainer('init').httpClient;
            httpClient.post('_action/topdata-elasticsearch-hacks-sw6/search-stats/reset', {})
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetSuccess'),
                    });
                    this.getList();
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetError'),
                    });
                    this.isLoading = false;
                });
        },

        onCancelReset() {
            this.showResetModal = false;
        },
    },
});
