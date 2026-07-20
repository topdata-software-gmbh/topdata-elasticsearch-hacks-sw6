import template from './synonym-list.html.twig';
import '../../component/synonym-form';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-synonym-list', {
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
            sortBy: 'term',
            sortDirection: 'ASC',
            limit: 25,
            activeModal: false,
            currentEntity: null,
            showDeleteModal: false,
            itemToDelete: null,
        };
    },

    computed: {
        deleteConfirmText() {
            if (!this.itemToDelete) return '';
            return this.$t('TopdataElasticsearchHacksSW6.topdata-es-synonym.deleteConfirmText', { term: this.itemToDelete.term });
        },

        repository() {
            return this.repositoryFactory.create('tdeh_synonym');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnTerm'),
                allowResize: true,
                primary: true,
                sortable: true,
            }, {
                property: 'synonyms',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnSynonyms'),
                allowResize: true,
            }, {
                property: 'scope',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnScope'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnCreatedAt'),
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

        onAddSynonym() {
            this.currentEntity = this.repository.create();
            this.currentEntity.term = '';
            this.currentEntity.synonyms = '';
            this.currentEntity.scope = 'global';
            this.activeModal = true;
        },

        onEditSynonym(item) {
            this.repository.get(item.id).then((entity) => {
                this.currentEntity = entity;
                this.activeModal = true;
            });
        },

        onCloseModal() {
            this.activeModal = false;
            this.currentEntity = null;
            this.getList();
        },

        onShowDeleteModal(item) {
            this.itemToDelete = item;
            this.showDeleteModal = true;
        },

        onCloseDeleteModal() {
            this.showDeleteModal = false;
            this.itemToDelete = null;
        },

        onDownloadCsv() {
            const httpClient = Shopware.Application.getContainer('init').httpClient;
            httpClient.get('_action/topdata-elasticsearch-hacks-sw6/synonyms/export', {
                responseType: 'blob',
            }).then((response) => {
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'synonyms.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.exportError'),
                });
            });
        },

        onConfirmDelete() {
            this.repository.delete(this.itemToDelete.id).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.deleteSuccess'),
                });
                this.onCloseDeleteModal();
                this.getList();
            }).catch(() => {
                this.onCloseDeleteModal();
            });
        },
    },
});
