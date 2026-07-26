import template from './suggestion-form.html.twig';

const { Component, Mixin } = Shopware;

Component.register('topdata-es-suggestion-form', {
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
        targetParamsFormatted: {
            get() {
                return typeof this.entity.targetParams === 'object'
                    ? JSON.stringify(this.entity.targetParams, null, 2)
                    : this.entity.targetParams || '';
            },
            set(val) {
                this.entity.targetParams = val;
            },
        },
    },

    methods: {
        async onSave() {
            this.isLoading = true;
            try {
                if (typeof this.entity.targetParams === 'string' && this.entity.targetParams.trim()) {
                    try {
                        this.entity.targetParams = JSON.parse(this.entity.targetParams);
                    } catch (e) {
                        this.createNotificationError({ message: 'Invalid JSON in targetParams' });
                        this.isLoading = false;
                        return;
                    }
                }
                await this.repository.save(this.entity, Shopware.Context.api);
                this.$emit('save');
            } catch (e) {
                this.createNotificationError({ message: e.message });
            } finally {
                this.isLoading = false;
            }
        },
    },

});
