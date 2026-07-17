import template from './synonym-list.html.twig';

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
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_es_synonym');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('topdata-es-synonym.columnTerm'),
                allowResize: true,
                primary: true,
                sortable: true,
            }, {
                property: 'synonyms',
                label: this.$tc('topdata-es-synonym.columnSynonyms'),
                allowResize: true,
            }, {
                property: 'createdAt',
                label: this.$tc('topdata-es-synonym.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },

        activeModalTitle() {
            if (!this.currentEntity) return '';
            return this.currentEntity.isNew()
                ? this.$tc('topdata-es-synonym.modalTitleAdd')
                : this.$tc('topdata-es-synonym.modalTitleEdit');
        },
    },

    mounted() {
        this.getList();
    },

    methods: {
        getList() {
            console.log('getList');
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

        onAddSynonym() {
            this.currentEntity = this.repository.create();
            this.currentEntity.term = '';
            this.currentEntity.synonyms = '';
            this.activeModal = true;
        },

        onEditSynonym(item) {
            this.currentEntity = item;
            this.activeModal = true;
        },

        onCloseModal() {
            this.activeModal = false;
            this.currentEntity = null;
            this.getList();
        },

        onSaveSynonym() {
            if (!this.currentEntity.term.trim() || !this.currentEntity.synonyms.trim()) {
                return;
            }

            this.isLoading = true;
            this.repository.save(this.currentEntity).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-es-synonym.saveSuccess'),
                });
                this.onCloseModal();
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
    },
});
