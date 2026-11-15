<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicDocument from '@/components/PublicDocument.vue';
import type { DocumentSection } from '@/components/PublicDocument.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    operator: string | null;
    baseDomain: string | null;
    canResetPassword: boolean;
}>();

const page = usePage();
const name = page.props.name;

const sections = computed((): DocumentSection[] => [
    {
        title: t('Who to contact'),
        intro: props.operator
            ? t(
                  'This installation of :name is run by :operator. Turn to them with questions, problems or requests about your account and the servers in it.',
                  { name, operator: props.operator },
              )
            : t(
                  'Whoever gave you your account, or the address of the Minecraft server you play on, runs this installation of :name. Turn to them with questions, problems or requests about your account and the servers in it.',
                  { name },
              ),
        points: [
            t(
                'When you ask for help, mention the server, what you did and what went wrong, and the version below.',
            ),
            t(
                'The makers of :name do not run this installation and cannot see your account or its servers.',
                { name },
            ),
        ],
    },
    {
        title: t('Signing in'),
        points: [
            t(
                'Accounts are made by an admin. There is no way to sign up yourself.',
            ),
            props.canResetPassword
                ? t(
                      'Forgot your password? Choose "Forgot your password?" on the login page and a reset link is sent to your email address.',
                  )
                : t(
                      'Forgot your password? Ask whoever runs this installation to reset it.',
                  ),
            t(
                'Lost the device with your authenticator app? Sign in with one of the recovery codes you saved when you switched two-factor authentication on. Without them, ask whoever runs this installation for help.',
            ),
        ],
    },
    {
        title: t('Using the panel'),
        points: [
            t(
                'You only see the servers that are shared with you. Ask an admin if one is missing.',
            ),
            t(
                'The share also decides what you may do on a server: read the logs, send commands, start and stop it, work with its files, make backups or change its settings. A button you do not see is one you have not been given.',
            ),
            t(
                'A server that does not start usually says why in its console. Check it before asking for help.',
            ),
            t(
                'You can change your name, email address, password, language and two-factor authentication under Settings.',
            ),
        ],
    },
    {
        title: t('Playing on a server'),
        points: [
            props.baseDomain
                ? t(
                      'Connect with Minecraft Java Edition to the address of the server, such as survival.:domain. The port is the default one, so you do not need to add it.',
                      { domain: props.baseDomain },
                  )
                : t(
                      'Connect with Minecraft Java Edition to the address of the server. The port is the default one, so you do not need to add it.',
                  ),
            t(
                'Your game must be on the same Minecraft version as the server, and on the same modpack if the server runs one.',
            ),
            t(
                'If the server says you are not on its allow list, ask the people who run that server to add your Minecraft username.',
            ),
        ],
    },
]);
</script>

<template>
    <PublicDocument
        :title="t('Support')"
        :description="
            t('How to get help with this installation of :name.', { name })
        "
        :sections="sections"
    >
        <template #footer>
            {{
                t('This installation runs :name version :version.', {
                    name,
                    version: page.props.appVersion,
                })
            }}
        </template>
    </PublicDocument>
</template>
