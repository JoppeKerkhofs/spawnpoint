<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dialog } from '@/lib/dialogs';
import { t } from '@/lib/i18n';

const answer = (value: string | null) => {
    dialog.current?.resolve(value);
    dialog.current = null;
};

const submit = () => {
    const request = dialog.current!;

    answer(request.input ? request.input.value : '');
};
</script>

<template>
    <Dialog
        :open="dialog.current !== null"
        @update:open="(open) => open || answer(null)"
    >
        <DialogContent v-if="dialog.current" class="sm:max-w-md">
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ dialog.current.title }}</DialogTitle>
                    <DialogDescription v-if="dialog.current.text">
                        {{ dialog.current.text }}
                    </DialogDescription>
                    <DialogDescription v-else class="sr-only">
                        {{ dialog.current.title }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="dialog.current.input" class="grid gap-2">
                    <Label for="dialog-input">{{
                        dialog.current.input.label
                    }}</Label>
                    <Input
                        id="dialog-input"
                        v-model="dialog.current.input.value"
                        required
                        spellcheck="false"
                        autocomplete="off"
                        maxlength="255"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        class="border-primary"
                        @click="answer(null)"
                    >
                        {{ t('Cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :variant="
                            dialog.current.destructive
                                ? 'destructive'
                                : 'default'
                        "
                    >
                        {{ dialog.current.action }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
