import template from './suggestion-list.html.twig';
import '../../component/suggestion-form';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-suggestion-list', {
    template,

    inject: ['repositoryFactory', 'acl'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            repository: null,
            items: null,
            showFormModal: false,
            currentEntity: null,
            isLoading: false,
        };
    },

    metaInfo() {
        return { title: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-suggestion.title') };
    },

    computed: {
        columns() {
            return [
                { property: 'term', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.term', allowResize: false },
                { property: 'targetType', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.targetType', allowResize: false },
                { property: 'targetUrl', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.targetUrl', allowResize: true },
                { property: 'priority', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.priority', allowResize: false },
                { property: 'active', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.active', allowResize: false },
                { property: 'createdAt', label: 'TopdataElasticsearchHacksSW6.topdata-es-suggestion.column.createdAt', allowResize: false },
            ];
        },
    },

    created() {
        this.repository = this.repositoryFactory.create('tdeh_search_suggestion');
        this.getList();
    },

    methods: {
        async getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort('priority', 'ASC'));

            try {
                const result = await this.repository.search(criteria, Shopware.Context.api);
                this.total = result.total;
                this.items = result;
            } catch (e) {
                this.createNotificationError({ message: e.message });
            } finally {
                this.isLoading = false;
            }
        },

        onAddSuggestion() {
            this.currentEntity = this.repository.create(Shopware.Context.api);
            this.currentEntity.active = true;
            this.currentEntity.priority = 0;
            this.currentEntity.targetType = 'custom';
            this.showFormModal = true;
        },

        onEditSuggestion(item) {
            this.currentEntity = item;
            this.showFormModal = true;
        },

        onCloseFormModal() {
            this.showFormModal = false;
            this.currentEntity = null;
            this.getList();
        },

        onDeleteSuggestion(item) {
            this.repository.delete(item.id, Shopware.Context.api).then(() => {
                this.getList();
            });
        },
    },
});
