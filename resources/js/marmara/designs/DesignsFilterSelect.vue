<script setup lang="ts">
/**
 * Marmara AI fork: one single-choice filter of the designs toolbar, with the
 * same trigger as TryPost's own toolbar filters (a ghost button with an icon,
 * a label and a chevron, as CalendarStatusFilter and MultiSelectFilter).
 * Short lists open a DropdownMenu; long ones (`searchable`) open a Popover
 * with a Command search, as SearchableSelect. The empty value ('') is the
 * "all" choice (`allLabel`).
 */
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed, ref, type Component } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

import type { FilterOption } from './types';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        options: FilterOption[];
        /** The filter's name: the trigger's text while nothing is picked, and its aria-label. */
        label: string;
        icon: Component;
        /** The "all" choice for ''. Without it there is always a value (the sort). */
        allLabel?: string;
        searchable?: boolean;
        searchPlaceholder?: string;
        emptyText?: string;
        testId: string;
        align?: 'start' | 'center' | 'end';
    }>(),
    {
        allLabel: undefined,
        searchable: false,
        searchPlaceholder: '',
        emptyText: '',
        align: 'start',
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const open = ref(false);

const selected = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);

const active = computed(
    () => props.allLabel !== undefined && props.modelValue !== '',
);

const ariaLabel = computed(() =>
    selected.value ? `${props.label}: ${selected.value.label}` : props.label,
);

const choose = (value: string): void => {
    open.value = false;

    if (value !== props.modelValue) {
        emit('update:modelValue', value);
    }
};

const triggerClass =
    'max-w-56 shrink-0 data-[state=open]:bg-accent max-sm:border max-sm:border-border-strong';
</script>

<template>
    <Popover v-if="searchable" v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                role="combobox"
                :aria-expanded="open"
                :aria-label="ariaLabel"
                :class="[triggerClass, { 'bg-accent': active }]"
                :data-testid="testId"
            >
                <component
                    :is="icon"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <span class="truncate max-sm:hidden">{{
                    selected?.label ?? label
                }}</span>
                <IconChevronDown
                    class="size-4 shrink-0 text-muted-foreground"
                />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-72 max-w-[calc(100vw-2rem)] p-0"
            :align="align"
        >
            <Command>
                <CommandInput :placeholder="searchPlaceholder" />
                <CommandList class="max-h-72">
                    <CommandEmpty>{{ emptyText }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-if="allLabel !== undefined"
                            :value="allLabel"
                            :data-testid="`${testId}-option-all`"
                            @select="choose('')"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                allLabel
                            }}</span>
                            <IconCheck
                                class="ms-auto size-4"
                                :class="modelValue === '' ? '' : 'invisible'"
                            />
                        </CommandItem>
                        <CommandItem
                            v-for="option in options"
                            :key="option.value"
                            :value="`${option.label} ${option.value}`"
                            :data-testid="`${testId}-option`"
                            @select="choose(option.value)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                option.label
                            }}</span>
                            <IconCheck
                                class="ms-auto size-4"
                                :class="
                                    modelValue === option.value
                                        ? ''
                                        : 'invisible'
                                "
                            />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>

    <DropdownMenu v-else v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                :aria-label="ariaLabel"
                :class="[triggerClass, { 'bg-accent': active }]"
                :data-testid="testId"
            >
                <component
                    :is="icon"
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <span class="truncate max-sm:hidden">{{
                    selected?.label ?? label
                }}</span>
                <IconChevronDown
                    class="size-4 shrink-0 text-muted-foreground"
                />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent :align="align" class="w-56">
            <template v-if="allLabel !== undefined">
                <DropdownMenuItem
                    :data-testid="`${testId}-option-all`"
                    @select="choose('')"
                >
                    <IconCheck
                        class="size-4"
                        :class="modelValue === '' ? '' : 'invisible'"
                    />
                    {{ allLabel }}
                </DropdownMenuItem>
                <DropdownMenuSeparator v-if="options.length > 0" />
            </template>
            <DropdownMenuItem
                v-for="option in options"
                :key="option.value"
                :data-testid="`${testId}-option-${option.value}`"
                @select="choose(option.value)"
            >
                <IconCheck
                    class="size-4"
                    :class="modelValue === option.value ? '' : 'invisible'"
                />
                <span class="min-w-0 truncate">{{ option.label }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
