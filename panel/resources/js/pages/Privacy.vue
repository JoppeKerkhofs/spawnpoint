<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicDocument from '@/components/PublicDocument.vue';
import type { DocumentSection } from '@/components/PublicDocument.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    operator: string | null;
    sessionMinutes: number;
    sendsMail: boolean;
    addsDnsRecords: boolean;
}>();

const page = usePage();
const name = page.props.name;

const sections = computed((): DocumentSection[] => [
    {
        title: t('Who is responsible'),
        intro: props.operator
            ? t(
                  ':name is software that someone runs on their own server. This installation is run by :operator, who decides what happens with the data in it and is the one to turn to with questions or requests.',
                  { name, operator: props.operator },
              )
            : t(
                  ':name is software that someone runs on their own server. Whoever gave you your account, or the address of the Minecraft server you play on, runs this installation: they decide what happens with the data in it and are the one to turn to with questions or requests.',
                  { name },
              ),
        points: [
            t(
                'The makers of :name do not receive any data from this installation.',
                { name },
            ),
        ],
    },
    {
        title: t('If you have an account'),
        intro: t('The panel keeps what it needs to let you sign in and work:'),
        points: [
            t('Your name and email address.'),
            t(
                'Your password, as a one-way hash that cannot be turned back into the password.',
            ),
            t('Your role, and which servers are shared with you.'),
            t(
                'If you switched them on: the secret and recovery codes of two-factor authentication, stored encrypted, and the public part of your passkeys.',
            ),
            t(
                'The API tokens you made, as hashes, with their name and when they were last used.',
            ),
            t('The language you chose.'),
            t(
                'If you linked one: your Minecraft username and the public id of that account.',
            ),
            t('The addresses of the webhooks you added, stored encrypted.'),
        ],
    },
    {
        title: t('What is recorded while you use the panel'),
        points: [
            t(
                'Your session: your IP address, your browser and when you were last active. A session ends after :minutes minutes without activity.',
                { minutes: props.sessionMinutes },
            ),
            t(
                'An activity log: who started, stopped, changed or deleted what, when, and from which IP address. Admins can read it. It is kept for as long as the installation exists.',
            ),
            t(
                'Technical logs with errors, which can hold the address of the page that failed.',
            ),
        ],
    },
    {
        title: t('If you play on a server'),
        intro: t(
            'You do not need an account on the panel to play. The Minecraft servers it manages keep their own records:',
        ),
        points: [
            t(
                'The server logs hold your Minecraft username, your IP address, when you joined and left, the chat and the commands you used.',
            ),
            t(
                'The world holds what you built and carried, and your username on allow and operator lists.',
            ),
            t(
                'Backups are copies of all of that, kept as long as the admin set.',
            ),
            t(
                'People with access to the server in the panel can see who is online and read these logs.',
            ),
            t(
                'When you join, the server checks your account with Mojang, the maker of Minecraft.',
            ),
        ],
    },
    {
        title: t('If you use the launcher'),
        intro: t(
            'The launcher is an optional Windows application for playing on these servers. It is downloaded from this panel and sends no usage statistics to anyone.',
        ),
        points: [
            t(
                'Downloading it, and its check for a newer version, are requests to this panel, which sees your IP address like any other visit.',
            ),
            t(
                'When you link the launcher to your account, the panel keeps the device name you gave it and an API token, as a hash, with when it was last used. You can revoke it under Settings.',
            ),
            t(
                'The launcher keeps that token on your computer, in the Windows Credential Manager. With it, it asks the panel for the servers shared with you, what they run and who is online.',
            ),
            t(
                'Your instances, worlds, mods and settings stay on your computer. The panel never receives them.',
            ),
            t(
                'To install the game the launcher downloads directly from Mojang, Fabric, Forge and NeoForge, and mods and modpacks with their pictures from Modrinth, CurseForge and Feed The Beast. Each of them sees your IP address and what was requested.',
            ),
            t(
                'It loads the faces of online players from mc-heads.net, which tells that service their usernames.',
            ),
            t(
                'If Discord runs on your computer, the launcher tells it that the launcher is open, or the name and Minecraft version of what you play, never the address of a server. You can switch this off in the launcher.',
            ),
        ],
    },
    {
        title: t('Cookies'),
        intro: t(
            'The panel only uses cookies it needs to work. There is no analytics, no advertising and no tracking.',
        ),
        points: [
            t('A session cookie that keeps you signed in.'),
            t('A security cookie that protects forms against forged requests.'),
            t('If you tick "Remember me": a cookie that signs you in again.'),
            t('Your choice of light or dark appearance.'),
        ],
    },
    {
        title: t('Other services'),
        intro: t(
            'To do its work the panel contacts a few outside services. Each of them sees the IP address the request comes from, and has its own privacy policy.',
        ),
        points: [
            t(
                'mc-heads.net: your browser loads the faces of players from it, which tells it the username or account id of those players.',
            ),
            ...(page.props.theme === 'blocky'
                ? [
                      t(
                          'Google Fonts: your browser loads the font of this theme from it.',
                      ),
                  ]
                : []),
            t(
                'Mojang: the server asks it for the list of Minecraft versions, and looks up a Minecraft username when someone links their account.',
            ),
            t(
                'Modrinth, CurseForge and Feed The Beast: the server sends them what an admin searches for when choosing a modpack, and your browser loads pack icons from them.',
            ),
            ...(props.addsDnsRecords
                ? [
                      t(
                          'Cloudflare: the server sends it the addresses of Minecraft servers, to add and remove DNS records.',
                      ),
                  ]
                : []),
            ...(props.sendsMail
                ? [
                      t(
                          'A mail server chosen by whoever runs this installation: it delivers invitations and password reset links to your email address.',
                      ),
                  ]
                : []),
            t(
                'Webhooks: a user can have the panel send a message to a service of their choice, such as a Discord channel, when something happens on a server. That message names the server and who did it.',
            ),
            t(
                'Download sites for server software, mods and maps, such as Docker Hub and Modrinth, when a server is installed or updated.',
            ),
        ],
    },
    {
        title: t('How long it is kept'),
        points: [
            t(
                'Your account and what belongs to it: until you or an admin deletes it.',
            ),
            t('Sessions: until they end or you sign out.'),
            t('The activity log: as long as the installation exists.'),
            t(
                'Server logs, worlds and backups: until an admin deletes them or the backup settings of a server remove old ones.',
            ),
        ],
    },
    {
        title: t('Your rights'),
        intro: t(
            'You can ask whoever runs this installation what is stored about you, to correct it, or to delete it.',
        ),
        points: [
            t(
                'You can change your name, email address, language and linked Minecraft account yourself, and delete your account, under Settings.',
            ),
            t(
                'If the European data protection rules apply to you, you can also complain to the data protection authority of your country.',
            ),
        ],
    },
]);
</script>

<template>
    <PublicDocument
        :title="t('Privacy policy')"
        :description="
            t(
                'What this installation of :name keeps about you, why, and who else gets to see it.',
                { name },
            )
        "
        :sections="sections"
    >
        <template #footer>
            {{
                t('This describes :name version :version.', {
                    name,
                    version: page.props.appVersion,
                })
            }}
        </template>
    </PublicDocument>
</template>
