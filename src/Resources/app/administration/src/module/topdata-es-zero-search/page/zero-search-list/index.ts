import template from './zero-search-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-zero-search-list', {
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
            return this.repositoryFactory.create('tdeh_zero_search');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.columnCreatedAt'),
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
            httpClient.get('_action/topdata-elasticsearch-hacks-sw6/zero-results/export', {
                responseType: 'blob',
            }).then((response) => {
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'zero-search-results.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.exportError'),
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
            httpClient.post('_action/topdata-elasticsearch-hacks-sw6/zero-results/reset', {})
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.resetSuccess'),
                    });
                    this.getList();
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-zero-search.resetError'),
                    });
                    this.isLoading = false;
                });
        },

        onCancelReset() {
            this.showResetModal = false;
        },
    },
});
