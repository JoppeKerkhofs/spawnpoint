<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    operator: string | null;
    sessionMinutes: number;
    sendsMail: boolean;
    addsDnsRecords: boolean;
}>();

const page = usePage();
const name = page.props.name;
const signedIn = computed(() => page.props.auth?.user != null);

// Each section: a heading, an optional introduction, and its points.
const sections = computed(() => [
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
    <Head :title="t('Privacy policy')" />

    <div class="min-h-svh bg-background text-foreground">
        <header class="border-b border-header-border bg-header text-white">
            <div
                class="mx-auto flex min-h-14 max-w-3xl flex-wrap items-center gap-x-6 px-6"
            >
                <a href="/" class="flex items-center gap-2.5 py-3 font-bold">
                    <AppLogoIcon class="size-[22px]" />
                    <span>{{ name }}</span>
                </a>
                <span class="flex-1" />
                <a
                    href="/"
                    class="py-3 text-[0.9375rem] text-white/85 underline"
                >
                    {{ signedIn ? t('Back to the servers') : t('Log in') }}
                </a>
            </div>
        </header>

        <main class="mx-auto flex max-w-3xl flex-col gap-6 px-6 pt-8 pb-16">
            <div>
                <h1 class="text-2xl leading-[1.3] font-bold text-primary">
                    {{ t('Privacy policy') }}
                </h1>
                <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                    {{
                        t(
                            'What this installation of :name keeps about you, why, and who else gets to see it.',
                            { name },
                        )
                    }}
                </p>
            </div>

            <section
                v-for="section in sections"
                :key="section.title"
                class="overflow-hidden rounded-md border border-border bg-card"
            >
                <h2 class="bg-muted px-4 pt-3 pb-2 font-bold text-primary">
                    {{ section.title }}
                </h2>
                <div class="flex flex-col gap-2.5 px-4 py-4">
                    <p v-if="section.intro">{{ section.intro }}</p>
                    <ul class="flex list-disc flex-col gap-1.5 pl-5">
                        <li v-for="point in section.points" :key="point">
                            {{ point }}
                        </li>
                    </ul>
                </div>
            </section>

            <p class="text-sm text-muted-foreground">
                {{
                    t('This describes :name version :version.', {
                        name,
                        version: page.props.appVersion,
                    })
                }}
            </p>
        </main>
    </div>
</template>
