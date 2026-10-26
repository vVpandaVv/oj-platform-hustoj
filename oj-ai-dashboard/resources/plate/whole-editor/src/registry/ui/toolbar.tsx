'use client';

import * as React from 'react';

import * as ToolbarPrimitive from '@radix-ui/react-toolbar';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { type VariantProps, cva } from 'class-variance-authority';
import { ChevronDown } from 'lucide-react';

import {
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuSeparator,
} from '@/registry/ui/dropdown-menu';
import { Separator } from '@/registry/ui/separator';
import { Tooltip, TooltipTrigger } from '@/registry/ui/tooltip';
import { cn } from '@/lib/utils';

export const Toolbar = React.forwardRef<
  HTMLDivElement,
  React.ComponentPropsWithoutRef<typeof ToolbarPrimitive.Root>
>(function Toolbar({ className, ...props }, ref) {
  return (
    <ToolbarPrimitive.Root
      ref={ref}
      className={cn('relative flex select-none items-center', className)}
      {...props}
    />
  );
});

export const ToolbarToggleGroup = React.forwardRef<
  HTMLDivElement,
  React.ComponentPropsWithoutRef<typeof ToolbarPrimitive.ToolbarToggleGroup>
>(function ToolbarToggleGroup({ className, ...props }, ref) {
  return (
    <ToolbarPrimitive.ToolbarToggleGroup
      ref={ref}
      className={cn('flex items-center', className)}
      {...props}
    />
  );
});

export const ToolbarLink = React.forwardRef<
  HTMLAnchorElement,
  React.ComponentPropsWithoutRef<typeof ToolbarPrimitive.Link>
>(function ToolbarLink({ className, ...props }, ref) {
  return (
    <ToolbarPrimitive.Link
      ref={ref}
      className={cn('font-medium underline underline-offset-4', className)}
      {...props}
    />
  );
});

export const ToolbarSeparator = React.forwardRef<
  HTMLDivElement,
  React.ComponentPropsWithoutRef<typeof ToolbarPrimitive.Separator>
>(function ToolbarSeparator({ className, ...props }, ref) {
  return (
    <ToolbarPrimitive.Separator
      ref={ref}
      className={cn('mx-2 my-1 w-px shrink-0 bg-border', className)}
      {...props}
    />
  );
});

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

const ToolbarButtonBase = React.forwardRef<HTMLButtonElement, ToolbarButtonProps>(
  function ToolbarButton(
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
  }
);

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
  HTMLButtonElement,
  React.ComponentPropsWithoutRef<typeof ToolbarPrimitive.ToggleItem> &
    VariantProps<typeof toolbarButtonVariants>
>(function ToolbarToggleItem({ className, size = 'sm', variant, ...props }, ref) {
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

type TooltipOwnProps = {
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
};

function withTooltip<TElement, TProps extends object>(
  Component: React.ForwardRefExoticComponent<
    React.PropsWithoutRef<TProps> & React.RefAttributes<TElement>
  >
) {
  return React.forwardRef<TElement, TProps & TooltipOwnProps>(
    function ExtendComponent(
      {
        tooltip,
        tooltipContentProps,
        tooltipProps,
        tooltipTriggerProps,
        ...props
      },
      ref
    ) {
    const [mounted, setMounted] = React.useState(false);

    React.useEffect(() => {
      setMounted(true);
    }, []);

    const component = (
      <Component ref={ref} {...(props as React.PropsWithoutRef<TProps>)} />
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
    }
  );
}

function TooltipContent({
  children,
  className,
  // CHANGE
  sideOffset = 4,
  ...props
}: React.ComponentProps<typeof TooltipPrimitive.Content>) {
  return (
    <TooltipPrimitive.Portal>
      <TooltipPrimitive.Content
        className={cn(
          'z-[100000] w-fit origin-(--radix-tooltip-content-transform-origin) text-balance rounded-md bg-primary px-3 py-1.5 text-primary-foreground text-xs',
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
      <DropdownMenuSeparator
        className={cn(
          'mb-0 shrink-0 first:[display:none]'
        )}
      />

      <DropdownMenuRadioGroup
        {...props}
        className={cn(
          'peer/menu-group group/menu-group my-1.5',
          className
        )}
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

