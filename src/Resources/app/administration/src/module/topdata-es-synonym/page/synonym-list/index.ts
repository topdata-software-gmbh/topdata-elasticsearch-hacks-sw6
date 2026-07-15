const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-synonym-list', {
    template: `
<div class="topdata-es-synonym-list">
    <sw-page class="topdata-es-synonym-list-page">
        <template #smart-bar-header>
            <h2>{{ $tc('topdata-es-synonym.title') }}</h2>
        </template>

        <template #smart-bar-actions>
            <sw-button variant="primary" @click="onAddSynonym">
                {{ $tc('topdata-es-synonym.buttonAdd') }}
            </sw-button>
        </template>

        <template #content>
            <sw-entity-listing
                v-if="items"
                :items="items"
                :columns="columns"
                :repository="repository"
                :criteria-limit="limit"
                :show-settings="true"
                :show-selection="false"
                :allow-view="false"
                :allow-edit="true"
                :allow-delete="true"
                :allow-inline-edit="false"
                :full-page="true"
                :sort-by="sortBy"
                :sort-direction="sortDirection"
                :is-loading="isLoading"
                @page-change="onPageChange"
                @column-sort="onSortColumn"
                @edit="onEditSynonym"
            >
                <template #column-createdAt="{ item }">
                    <sw-time-ago :date="item.createdAt" :date-time-format="{ month: '2-digit', day: '2-digit' }" />
                </template>
            </sw-entity-listing>

            <sw-modal
                v-if="activeModal"
                :title="activeModalTitle"
                @modal-close="onCloseModal"
            >
                <sw-text-field
                    v-model="currentEntity.term"
                    required
                    :label="$tc('topdata-es-synonym.labelTerm')"
                ></sw-text-field>

                <sw-textarea-field
                    v-model="currentEntity.synonyms"
                    required
                    :label="$tc('topdata-es-synonym.labelSynonyms')"
                    :placeholder="$tc('topdata-es-synonym.placeholderSynonyms')"
                ></sw-textarea-field>

                <template #modal-footer>
                    <sw-button size="small" @click="onCloseModal">
                        {{ $tc('global.default.cancel') }}
                    </sw-button>
                    <sw-button variant="primary" size="small" @click="onSaveSynonym">
                        {{ $tc('global.default.save') }}
                    </sw-button>
                </template>
            </sw-modal>
        </template>
    </sw-page>
</div>
    `,

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
