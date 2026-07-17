import template from './synonym-list.html.twig';

const { Component, Mixin } = Shopware;

Component.register('topdata-es-synonym-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            isLoading: true,
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

    methods: {

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
            this.$refs.listing?.getList();
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
    },
});
