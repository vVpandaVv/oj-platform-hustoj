'use client';

import * as React from 'react';

import * as ToolbarPrimitive from '@radix-ui/react-toolbar';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { type VariantProps, cva } from 'class-variance-authority';
import { ChevronDown } from 'lucide-react';

import {
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipTrigger } from '@/components/ui/tooltip';
import { getContentsEditorOverlayRoot } from '@/lib/overlay-root';
import { cn } from '@/lib/utils';

export function Toolbar({
  className,
  ...props
}: React.ComponentProps<typeof ToolbarPrimitive.Root>) {
  return (
    <ToolbarPrimitive.Root
      className={cn('relative flex select-none items-center', className)}
      {...props}
    />
  );
}

export function ToolbarToggleGroup({
  className,
  ...props
}: React.ComponentProps<typeof ToolbarPrimitive.ToolbarToggleGroup>) {
  return (
    <ToolbarPrimitive.ToolbarToggleGroup
      className={cn('flex items-center', className)}
      {...props}
    />
  );
}

export function ToolbarLink({
  className,
  ...props
}: React.ComponentProps<typeof ToolbarPrimitive.Link>) {
  return (
    <ToolbarPrimitive.Link
      className={cn('font-medium underline underline-offset-4', className)}
      {...props}
    />
  );
}

export function ToolbarSeparator({
  className,
  ...props
}: React.ComponentProps<typeof ToolbarPrimitive.Separator>) {
  return (
    <ToolbarPrimitive.Separator
      className={cn('mx-2 my-1 w-px shrink-0 bg-border', className)}
      {...props}
    />
  );
}

// From toggleVariants
const toolbarButtonVariants = cva(
  "inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium text-sm outline-none transition-[color,box-shadow] hover:bg-muted hover:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 aria-checked:bg-accent aria-checked:text-accent-foreground aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg:not([class*='size-'])]:size-4 [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    defaultVariants: {
      size: 'default',
      variant: 'default',
    },
    variants: {
      size: {
        default: 'h-9 min-w-9 px-2',
        lg: 'h-10 min-w-10 px-2.5',
        sm: 'h-8 min-w-8 px-1.5',
      },
      variant: {
        default: 'bg-transparent',
        outline:
          'border border-input bg-transparent shadow-xs hover:bg-accent hover:text-accent-foreground',
      },
    },
  }
);

const dropdownArrowVariants = cva(
  cn(
    'inline-flex items-center justify-center rounded-r-md font-medium text-foreground text-sm transition-colors disabled:pointer-events-none disabled:opacity-50'
  ),
  {
    defaultVariants: {
      size: 'sm',
      variant: 'default',
    },
    variants: {
      size: {
        default: 'h-9 w-6',
        lg: 'h-10 w-8',
        sm: 'h-8 w-4',
      },
      variant: {
        default:
          'bg-transparent hover:bg-muted hover:text-muted-foreground aria-checked:bg-accent aria-checked:text-accent-foreground',
        outline:
          'border border-input border-l-0 bg-transparent hover:bg-accent hover:text-accent-foreground',
      },
    },
  }
);

type ToolbarButtonProps = {
  isDropdown?: boolean;
  pressed?: boolean;
} & Omit<
  React.ComponentPropsWithoutRef<typeof ToolbarToggleItem>,
  'asChild' | 'value'
> &
  VariantProps<typeof toolbarButtonVariants>;

const ToolbarButtonBase = React.forwardRef<HTMLButtonElement, ToolbarButtonProps>(function ToolbarButton(
  {
  children,
  className,
  isDropdown,
  pressed,
  size = 'sm',
  variant,
  ...props
  },
  ref
) {
  const isMenuTrigger =
    isDropdown ||
    'aria-haspopup' in props ||
    'data-state' in props ||
    'aria-expanded' in props;

  if (typeof pressed === 'boolean' && isMenuTrigger) {
    return (
      <ToolbarPrimitive.Button
        aria-checked={pressed}
        ref={ref}
        className={cn(
          toolbarButtonVariants({
            size,
            variant,
          }),
          isDropdown && 'justify-between gap-1 pr-1',
          className
        )}
        {...props}
      >
        {isDropdown ? (
          <>
            <div className="flex flex-1 items-center gap-2 whitespace-nowrap">
              {children}
            </div>
            <div>
              <ChevronDown
                className="size-3.5 text-muted-foreground"
                data-icon
              />
            </div>
          </>
        ) : (
          children
        )}
      </ToolbarPrimitive.Button>
    );
  }

  return typeof pressed === 'boolean' ? (
    <ToolbarToggleGroup disabled={props.disabled} value="single" type="single">
      <ToolbarToggleItem
        ref={ref}
        className={cn(
          toolbarButtonVariants({
            size,
            variant,
          }),
          isDropdown && 'justify-between gap-1 pr-1',
          className
        )}
        value={pressed ? 'single' : ''}
        {...props}
      >
        {isDropdown ? (
          <>
            <div className="flex flex-1 items-center gap-2 whitespace-nowrap">
              {children}
            </div>
            <div>
              <ChevronDown
                className="size-3.5 text-muted-foreground"
                data-icon
              />
            </div>
          </>
        ) : (
          children
        )}
      </ToolbarToggleItem>
    </ToolbarToggleGroup>
  ) : (
    <ToolbarPrimitive.Button
      ref={ref}
      className={cn(
        toolbarButtonVariants({
          size,
          variant,
        }),
        isDropdown && 'pr-1',
        className
      )}
      {...props}
    >
      {children}
    </ToolbarPrimitive.Button>
  );
});

export const ToolbarButton = withTooltip(ToolbarButtonBase);

export function ToolbarSplitButton({
  className,
  ...props
}: React.ComponentPropsWithoutRef<typeof ToolbarButton>) {
  return (
    <ToolbarButton
      className={cn('group flex gap-0 px-0 hover:bg-transparent', className)}
      {...props}
    />
  );
}

type ToolbarSplitButtonPrimaryProps = Omit<
  React.ComponentPropsWithoutRef<typeof ToolbarToggleItem>,
  'value'
> &
  VariantProps<typeof toolbarButtonVariants>;

export function ToolbarSplitButtonPrimary({
  children,
  className,
  size = 'sm',
  variant,
  ...props
}: ToolbarSplitButtonPrimaryProps) {
  return (
    <span
      className={cn(
        toolbarButtonVariants({
          size,
          variant,
        }),
        'rounded-r-none',
        'group-data-[pressed=true]:bg-accent group-data-[pressed=true]:text-accent-foreground',
        className
      )}
      {...props}
    >
      {children}
    </span>
  );
}

export function ToolbarSplitButtonSecondary({
  className,
  size,
  variant,
  ...props
}: React.ComponentPropsWithoutRef<'span'> &
  VariantProps<typeof dropdownArrowVariants>) {
  return (
    <span
      className={cn(
        dropdownArrowVariants({
          size,
          variant,
        }),
        'group-data-[pressed=true]:bg-accent group-data-[pressed=true]:text-accent-foreground',
        className
      )}
      onClick={(e) => e.stopPropagation()}
      role="button"
      {...props}
    >
      <ChevronDown className="size-3.5 text-muted-foreground" data-icon />
    </span>
  );
}

export const ToolbarToggleItem = React.forwardRef<
  React.ElementRef<typeof ToolbarPrimitive.ToggleItem>,
  React.ComponentProps<typeof ToolbarPrimitive.ToggleItem> &
    VariantProps<typeof toolbarButtonVariants>
>(function ToolbarToggleItem({
  className,
  size = 'sm',
  variant,
  ...props
}, ref) {
  return (
    <ToolbarPrimitive.ToggleItem
      ref={ref}
      className={cn(toolbarButtonVariants({ size, variant }), className)}
      {...props}
    />
  );
});

export function ToolbarGroup({
  children,
  className,
}: React.ComponentProps<'div'>) {
  return (
    <div
      className={cn(
        'group/toolbar-group',
        'relative flex',
        className
      )}
    >
      <div className="flex items-center">{children}</div>

      <div className="group-last/toolbar-group:hidden! mx-1.5 py-0.5">
        <Separator orientation="vertical" />
      </div>
    </div>
  );
}

type TooltipProps = {
  tooltip?: React.ReactNode;
  tooltipContentProps?: Omit<
    React.ComponentPropsWithoutRef<typeof TooltipContent>,
    'children'
  >;
  tooltipProps?: Omit<
    React.ComponentPropsWithoutRef<typeof Tooltip>,
    'children'
  >;
  tooltipTriggerProps?: React.ComponentPropsWithoutRef<typeof TooltipTrigger>;
} & React.ComponentPropsWithoutRef<typeof ToolbarButtonBase>;

function withTooltip(Component: typeof ToolbarButtonBase) {
  return React.forwardRef<HTMLButtonElement, TooltipProps>(function ExtendComponent({
    tooltip,
    tooltipContentProps,
    tooltipProps,
    tooltipTriggerProps,
    ...props
  }, ref) {
    const [mounted, setMounted] = React.useState(false);

    React.useEffect(() => {
      setMounted(true);
    }, []);

    const labelledProps =
      tooltip && typeof tooltip === 'string' && !('aria-label' in props)
        ? { ...props, 'aria-label': tooltip }
        : props;

    const component = (
      <Component ref={ref} {...labelledProps} />
    );

    if (tooltip && mounted) {
      return (
        <Tooltip {...tooltipProps}>
          <TooltipTrigger asChild {...tooltipTriggerProps}>
            {component}
          </TooltipTrigger>

          <TooltipContent {...tooltipContentProps}>{tooltip}</TooltipContent>
        </Tooltip>
      );
    }

    return component;
  });
}

function TooltipContent({
  children,
  className,
  // CHANGE
  sideOffset = 4,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal container={getContentsEditorOverlayRoot()}>
      <TooltipPrimitive.Content
        className={cn(
          'z-[2147483000] w-fit origin-(--radix-tooltip-content-transform-origin) text-balance rounded-md bg-primary px-3 py-1.5 text-primary-foreground text-xs',
          className
        )}
        data-slot="tooltip-content"
        sideOffset={sideOffset}
        {...props}
      >
        {children}
        {/* CHANGE */}
        {/* <TooltipPrimitive.Arrow className="z-50 size-2.5 translate-y-[calc(-50%_-_2px)] rotate-45 rounded-[2px] bg-primary fill-primary" /> */}
      </TooltipPrimitive.Content>
    </TooltipPrimitive.Portal>
  );
}

export function ToolbarMenuGroup({
  children,
  className,
  label,
  ...props
}: React.ComponentProps<typeof DropdownMenuRadioGroup> & { label?: string }) {
  return (
    <>
      <DropdownMenuRadioGroup
        {...props}
        className={cn('group/menu-group my-1.5 not-first:border-t not-first:pt-1.5', className)}
      >
        {label && (
          <DropdownMenuLabel className="select-none font-semibold text-muted-foreground text-xs">
            {label}
          </DropdownMenuLabel>
        )}
        {children}
      </DropdownMenuRadioGroup>
    </>
  );
}
