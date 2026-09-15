import { computed, ref } from "vue";

const routeLoading = ref(false);
const backgroundTaskCount = ref(0);

export const isPageLoading = computed(() => routeLoading.value || backgroundTaskCount.value > 0);

export function setRouteLoading(loading: boolean) {
    routeLoading.value = loading;
}

export function beginPageLoadingTask(): () => void {
    backgroundTaskCount.value += 1;
    let finished = false;

    return () => {
        if (finished) return;
        finished = true;
        backgroundTaskCount.value = Math.max(0, backgroundTaskCount.value - 1);
    };
}
