<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import CenteredColumn from '../Components/CenteredColumn.vue';
import SecurityCardBody from '../Components/SecurityCardBody.vue';
import WelcomeCardBody from '../Components/WelcomeCardBody.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const page = usePage();
</script>

<template>
  <Head>
    <title>{{ page.props.appName }}</title>
    <meta
      name="description"
      content="Share your availability with friends for simpler event planning across timezones"
    >
  </Head>

  <CenteredColumn size="wide">
    <div class="card mb-4">
      <div class="card-body">
        <WelcomeCardBody />

        <div class="mb-3"><!-- spacing --></div>

        <SecurityCardBody />
      </div>
    </div>
  </CenteredColumn>
</template>
