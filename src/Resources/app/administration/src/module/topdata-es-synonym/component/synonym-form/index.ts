import template from './synonym-form.html.twig';

const { Component, Mixin } = Shopware;

Component.register('topdata-es-synonym-form', {
    template,

    mixins: [
        Mixin.getByName('notification'),
    ],

    props: {
        entity: {
            type: Object,
            required: true,
        },
        repository: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isLoading: false,
        };
    },

    computed: {
        isNew() {
            return this.entity.isNew();
        },

        modalTitle() {
            return this.isNew
                ? this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.modalTitleAdd')
                : this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.modalTitleEdit');
        },
    },

    methods: {
        onSave() {
            if (!this.entity.term.trim() || !this.entity.synonyms.trim()) {
                return;
            }

            this.isLoading = true;
            this.repository.save(this.entity).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.saveSuccess'),
                });
                this.$emit('save', this.entity);
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onCancel() {
            this.$emit('cancel');
        },
    },
});
